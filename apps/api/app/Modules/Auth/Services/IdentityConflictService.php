<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Exceptions\AuthException;
use App\Modules\Auth\Models\IdentityConflict;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IdentityConflictService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<int, int>  $candidateUserIds
     */
    public function record(
        string $kind,
        string $provider,
        string $providerHash,
        ?string $providerValue,
        ?string $unionHash,
        ?string $unionValue,
        ?User $sourceUser,
        ?User $targetUser,
        array $candidateUserIds = [],
    ): IdentityConflict {
        $conflict = IdentityConflict::query()
            ->where('kind', $kind)
            ->where('provider', $provider)
            ->where('provider_user_id_hash', $providerHash)
            ->when(
                $sourceUser === null,
                fn ($query) => $query->whereNull('source_user_id'),
                fn ($query) => $query->where('source_user_id', $sourceUser->id),
            )
            ->where('status', 'pending')
            ->first();

        if ($conflict !== null) {
            return $conflict;
        }

        return IdentityConflict::query()->create([
            'kind' => $kind,
            'provider' => $provider,
            'provider_user_id_hash' => $providerHash,
            'provider_user_value' => $providerValue,
            'union_id_hash' => $unionHash,
            'union_value' => $unionValue,
            'source_user_id' => $sourceUser?->id,
            'target_user_id' => $targetUser?->id,
            'candidate_user_ids' => array_values(array_unique($candidateUserIds)),
            'status' => 'pending',
        ]);
    }

    public function exception(IdentityConflict $conflict): AuthException
    {
        return new AuthException(
            'IDENTITY_MERGE_REQUIRED',
            '检测到身份已关联其他账号，已进入人工处理，请勿重复绑定。',
            409,
            ['conflict_id' => $conflict->public_id],
        );
    }

    /**
     * A verified phone proves control of the target account. The source account is
     * an unbound WeChat transition account and cannot access business endpoints.
     *
     * @return array{target: User, identity_id: int, conflict: IdentityConflict}
     */
    public function resolveVerifiedPhoneOwnership(IdentityConflict $conflict, User $source): array
    {
        return DB::transaction(function () use ($conflict, $source): array {
            $conflict = IdentityConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            $source = User::query()->lockForUpdate()->findOrFail($source->id);

            if ($conflict->status !== 'pending'
                || $conflict->kind !== 'phone_already_bound'
                || $conflict->source_user_id !== $source->id) {
                throw new AuthException(
                    'IDENTITY_CONFLICT_CHANGED',
                    '账户关联状态已变化，请重新登录后再试。',
                    409,
                );
            }
            if ($source->phone_hash !== null || $source->phone_verified_at !== null) {
                throw new AuthException(
                    'ACCOUNT_MERGE_REQUIRES_SUPPORT',
                    '两个账户都已绑定手机号，为保护资产安全，请联系客服核验后合并。',
                    409,
                    ['conflict_id' => $conflict->public_id],
                );
            }

            $target = User::query()->lockForUpdate()->find($conflict->target_user_id);
            if ($target === null
                || $target->status !== 'active'
                || $target->phone_hash !== $conflict->provider_user_id_hash) {
                throw new AuthException(
                    'TARGET_ACCOUNT_UNAVAILABLE',
                    '手机号原账户当前不可用，请联系客服处理。',
                    409,
                    ['conflict_id' => $conflict->public_id],
                );
            }

            $identity = UserIdentity::query()
                ->where('user_id', $source->id)
                ->whereIn('provider', ['wechat_mini', 'wechat_oa'])
                ->orderByDesc('last_login_at')
                ->lockForUpdate()
                ->first();
            if ($identity === null) {
                throw new AuthException(
                    'WECHAT_IDENTITY_REQUIRED',
                    '当前微信身份已失效，请重新进行微信登录。',
                    409,
                );
            }

            $before = $this->present($conflict);
            $this->attachToTarget($conflict, $target);
            $conflict->forceFill([
                'status' => 'resolved',
                'resolved_by_admin_id' => null,
                'resolution' => 'verified_phone_account_merge',
                'resolution_reason' => '用户完成手机号验证，自动归并微信临时账户并进入手机号原账户。',
                'resolved_at' => now(),
            ])->save();

            $after = $this->present($conflict->fresh());
            $this->audit->record(
                null,
                'identity_conflict.auto_resolved',
                'identity_conflict',
                $conflict->public_id,
                $before,
                $after,
                'verified_phone_ownership',
            );

            return [
                'target' => $target->fresh(),
                'identity_id' => $identity->id,
                'conflict' => $conflict->fresh(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function present(IdentityConflict $conflict): array
    {
        $conflict->loadMissing(['sourceUser', 'targetUser', 'resolvedBy']);

        return [
            'id' => $conflict->public_id,
            'kind' => $conflict->kind,
            'provider' => $conflict->provider,
            'status' => $conflict->status,
            'source_user_id' => $conflict->sourceUser?->public_id,
            'target_user_id' => $conflict->targetUser?->public_id,
            'candidate_count' => count($conflict->candidate_user_ids ?? []),
            'resolution' => $conflict->resolution,
            'resolution_reason' => $conflict->resolution_reason,
            'resolved_by' => $conflict->resolvedBy?->public_id,
            'created_at' => $conflict->created_at?->toIso8601String(),
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(
        IdentityConflict $conflict,
        AdminUser $admin,
        string $resolution,
        string $reason,
        ?string $targetUserPublicId,
    ): array {
        return DB::transaction(function () use ($conflict, $admin, $resolution, $reason, $targetUserPublicId): array {
            $conflict = IdentityConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($conflict->status !== 'pending') {
                throw ValidationException::withMessages(['conflict' => ['该身份冲突已处理。']]);
            }

            $before = $this->present($conflict);
            if ($resolution === 'attach_to_target') {
                $target = $this->resolveTarget($conflict, $targetUserPublicId);
                $this->attachToTarget($conflict, $target);
                $conflict->target_user_id = $target->id;
            }

            $conflict->forceFill([
                'status' => $resolution === 'reject' ? 'rejected' : 'resolved',
                'resolved_by_admin_id' => $admin->id,
                'resolution' => $resolution,
                'resolution_reason' => $reason,
                'resolved_at' => now(),
            ])->save();

            $after = $this->present($conflict->fresh());
            $this->audit->record(
                $admin,
                'identity_conflict.resolve',
                'identity_conflict',
                $conflict->public_id,
                $before,
                $after,
                $reason,
            );

            return $after;
        });
    }

    private function resolveTarget(IdentityConflict $conflict, ?string $targetUserPublicId): User
    {
        $target = $conflict->target_user_id !== null
            ? User::query()->find($conflict->target_user_id)
            : User::query()->where('public_id', $targetUserPublicId)->first();

        if ($target === null || $target->status !== 'active') {
            throw ValidationException::withMessages(['target_user_id' => ['请选择有效的目标用户。']]);
        }

        $candidates = $conflict->candidate_user_ids ?? [];
        if ($conflict->target_user_id === null && ! in_array($target->id, $candidates, true)) {
            throw ValidationException::withMessages(['target_user_id' => ['目标用户不在冲突候选范围内。']]);
        }

        return $target;
    }

    private function attachToTarget(IdentityConflict $conflict, User $target): void
    {
        if ($conflict->kind === 'union_collision') {
            UserIdentity::query()->updateOrCreate(
                [
                    'provider' => $conflict->provider,
                    'provider_user_id' => $conflict->provider_user_id_hash,
                ],
                [
                    'user_id' => $target->id,
                    'provider_user_value' => $conflict->provider_user_value,
                    'union_id' => $conflict->union_id_hash,
                    'union_value' => $conflict->union_value,
                    'metadata' => ['resolved_from_conflict' => $conflict->public_id],
                    'last_login_at' => now(),
                ],
            );
        } elseif ($conflict->kind === 'phone_already_bound' && $conflict->source_user_id !== null) {
            UserIdentity::query()
                ->where('user_id', $conflict->source_user_id)
                ->whereIn('provider', ['wechat_mini', 'wechat_oa'])
                ->update(['user_id' => $target->id, 'updated_at' => now()]);
        }

        if ($conflict->source_user_id !== null && $conflict->source_user_id !== $target->id) {
            UserSession::query()
                ->where('user_id', $conflict->source_user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoke_reason' => 'identity_merged', 'updated_at' => now()]);
            UserDevice::query()
                ->where('user_id', $conflict->source_user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $source = User::query()->find($conflict->source_user_id);
            if ($source !== null
                && $source->phone_hash === null
                && ! $source->identities()->exists()) {
                $source->update(['status' => 'merged', 'merged_into_user_id' => $target->id]);
            }
        }
    }
}
