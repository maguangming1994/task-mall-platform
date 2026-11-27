<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreManagerSession;
use App\Modules\Store\Models\StoreMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreManagerAuthService
{
    public function __construct(private readonly StoreCampusService $campuses) {}

    public function issueSession(User $user, Store $store, ?string $ip, ?string $userAgent): array
    {
        return DB::transaction(function () use ($user, $store, $ip, $userAgent): array {
            if (! $store->isActive()) {
                throw ValidationException::withMessages(['store' => ['门店未通过审核或已停用，暂时无法进入门店工作台。']]);
            }
            $member = StoreMember::query()
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($member === null) {
                throw ValidationException::withMessages(['store' => ['当前账号不是该门店成员。']]);
            }
            StoreManagerSession::query()
                ->where('store_id', $store->id)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $ttl = max(300, (int) config('store_auth.session_ttl_seconds'));
            $session = StoreManagerSession::query()->create([
                'token_hash' => $this->tokenHash($token),
                'store_id' => $store->id,
                'user_id' => $user->id,
                'last_active_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
                'ip_hash' => $this->fingerprint($ip),
                'user_agent_hash' => $this->fingerprint($userAgent),
            ]);

            return [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => $ttl,
                'expires_at' => $session->expires_at->toIso8601String(),
                'store' => $this->presentStore($store),
                'member' => $this->presentMember($member),
            ];
        });
    }

    public function logout(StoreManagerSession $session): void
    {
        $session->update(['revoked_at' => now()]);
    }

    public function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, hash('sha256', (string) config('store_auth.hash_secret')));
    }

    /** @return array<string, mixed> */
    public function presentStore(Store $store): array
    {
        return [
            'id' => $store->public_id,
            'name' => $store->name,
            'logo_url' => $store->logo_url,
            'description' => $store->description,
            'contact_phone' => $store->contact_phone,
            'address' => $store->address,
            'business_hours' => $store->business_hours,
            'status' => $store->status,
            'reject_reason' => $store->reject_reason,
            'commission_rate_bps' => (int) $store->commission_rate_bps,
            'shipping_fee_amount' => (int) $store->shipping_fee_amount,
            'free_shipping_threshold' => $store->free_shipping_threshold === null ? null : (int) $store->free_shipping_threshold,
            // 门店开通校区（仅生效中），工作台按校区管理库存、订单与结算
            'campuses' => $this->campuses->links($store, true)->map(fn ($link): array => $this->campuses->present($link, $store))->values()->all(),
            'applied_at' => $store->applied_at?->toIso8601String(),
            'reviewed_at' => $store->reviewed_at?->toIso8601String(),
            'created_at' => $store->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentMember(StoreMember $member): array
    {
        $member->loadMissing('user');

        return [
            'id' => $member->public_id,
            'role' => $member->role,
            'role_label' => match ($member->role) {
                StoreMember::ROLE_OWNER => '店主',
                StoreMember::ROLE_MANAGER => '店长',
                default => '店员',
            },
            'can_manage' => $member->canManage(),
            'nickname' => $member->user?->nickname,
            'avatar_url' => $member->user?->avatar_file_id,
            'joined_at' => $member->joined_at?->toIso8601String(),
        ];
    }

    private function fingerprint(?string $value): ?string
    {
        return empty($value) ? null : hash_hmac('sha256', $value, hash('sha256', (string) config('store_auth.hash_secret')));
    }
}
