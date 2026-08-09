<?php

namespace App\Modules\Assets\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\AssetAdjustmentRequest;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetAdjustmentService
{
    public function __construct(
        private readonly AssetLedgerService $ledger,
        private readonly SystemSettingService $settings,
    ) {}

    public function request(AdminUser $admin, User $user, array $input, string $idempotencyKey): AssetAdjustmentRequest
    {
        $allowed = [
            'points' => ['increase', 'decrease', 'clear'],
            'wallet' => ['increase', 'decrease', 'clear'],
            'coupon' => ['issue'],
        ];
        if (! in_array($input['action'], $allowed[$input['asset_type']] ?? [], true)) {
            throw ValidationException::withMessages(['action' => ['资产类型与操作不匹配。']]);
        }
        $hash = $this->fingerprint('asset-adjust:'.$admin->id.':'.$user->id.':'.$idempotencyKey);
        $existing = AssetAdjustmentRequest::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $requiresReview = $this->requiresReview($input);

        return DB::transaction(function () use ($admin, $user, $input, $hash, $requiresReview): AssetAdjustmentRequest {
            $request = AssetAdjustmentRequest::query()->create([
                'user_id' => $user->id, 'asset_type' => $input['asset_type'], 'action' => $input['action'],
                'amount' => $input['amount'] ?? null,
                'coupon_template_id' => isset($input['coupon_template_id']) ? CouponTemplate::query()->where('public_id', $input['coupon_template_id'])->firstOrFail()->id : null,
                'status' => $requiresReview ? 'pending' : 'approved', 'requires_review' => $requiresReview,
                'reason' => $input['reason'], 'requested_by_admin_id' => $admin->id, 'idempotency_key_hash' => $hash,
            ]);
            if (! $requiresReview) {
                $this->apply($request, $admin);
            }

            return $request->fresh();
        });
    }

    public function review(AdminUser $reviewer, AssetAdjustmentRequest $request, string $decision, string $note, string $idempotencyKey): AssetAdjustmentRequest
    {
        $hash = $this->fingerprint('asset-review:'.$request->id.':'.$idempotencyKey);
        if (AssetAdjustmentRequest::query()->where('review_idempotency_key_hash', $hash)->exists()) {
            return $request->fresh();
        }

        return DB::transaction(function () use ($reviewer, $request, $decision, $note, $hash): AssetAdjustmentRequest {
            $locked = AssetAdjustmentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => ['当前资产调整申请不在待复核状态。']]);
            }
            if ($locked->requested_by_admin_id === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => ['资产高风险调整必须由另一位管理员复核。']]);
            }
            $locked->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'reviewed_by_admin_id' => $reviewer->id, 'review_note' => $note,
                'review_idempotency_key_hash' => $hash, 'reviewed_at' => now(),
            ]);
            if ($decision === 'approve') {
                $this->apply($locked, $reviewer);
            }

            return $locked->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function present(AssetAdjustmentRequest $request): array
    {
        return [
            'id' => $request->public_id, 'asset_type' => $request->asset_type, 'action' => $request->action,
            'amount' => $request->amount, 'status' => $request->status, 'requires_review' => $request->requires_review,
            'reason' => $request->reason, 'review_note' => $request->review_note,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(), 'applied_at' => $request->applied_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    private function apply(AssetAdjustmentRequest $request, AdminUser $actor): void
    {
        if ($request->applied_at !== null) {
            return;
        }
        $user = User::query()->findOrFail($request->user_id);
        $event = 'admin-adjustment:'.$request->public_id;
        if ($request->asset_type === 'points') {
            if ($request->action === 'increase') {
                $this->ledger->grantPoints($user, $request->amount, 'manual_adjustment', $request->public_id, $request->reason, $event, $actor);
            } else {
                $amount = $request->action === 'clear' ? $this->ledger->account($user)->points_available : $request->amount;
                if ($amount > 0) {
                    $this->ledger->debitPoints($user, $amount, $request->reason, $event, $actor, $request->action === 'clear' ? 'clear' : 'adjust');
                }
            }
        } elseif ($request->asset_type === 'wallet') {
            $account = $this->ledger->account($user);
            $amount = $request->action === 'clear' ? $account->wallet_available_cents : $request->amount;
            $signed = $request->action === 'increase' ? $amount : -$amount;
            if ($amount > 0) {
                $this->ledger->changeWallet($user, $signed, $request->reason, $event, $actor, $request->action === 'clear' ? 'clear' : 'adjust');
            }
        } elseif ($request->asset_type === 'coupon' && $request->action === 'issue') {
            $template = CouponTemplate::query()->findOrFail($request->coupon_template_id);
            $this->ledger->issueCoupons($user, $template, $request->amount, $request->reason, $event, $actor);
        } else {
            throw ValidationException::withMessages(['action' => ['资产类型与操作不匹配。']]);
        }
        $request->update(['status' => 'applied', 'applied_at' => now()]);
    }

    private function requiresReview(array $input): bool
    {
        if ($input['action'] === 'clear') {
            return (bool) $this->settings->read('risk.asset_clear_requires_review')['value'];
        }
        $amount = (int) ($input['amount'] ?? 0);
        if ($input['asset_type'] === 'points') {
            return $amount > (int) $this->settings->read('risk.points_adjustment_second_review')['value'];
        }
        if ($input['asset_type'] === 'wallet') {
            return $amount > (int) $this->settings->read('risk.wallet_adjustment_second_review_cents')['value'];
        }

        return $input['asset_type'] === 'coupon' && $amount > 1000;
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
