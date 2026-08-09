<?php

namespace App\Modules\Assets\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Assets\Models\CouponTransaction;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointDebt;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\Settings\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssetLedgerService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function grantPoints(User $user, int $points, string $sourceCode, ?string $businessId, string $reason, string $eventKey, ?AdminUser $admin = null): UserAssetAccount
    {
        $hash = $this->fingerprint('points:'.$eventKey);
        if (PointTransaction::query()->where('event_key_hash', $hash)->exists()) {
            return $this->account($user);
        }

        return DB::transaction(function () use ($user, $points, $sourceCode, $businessId, $reason, $hash, $admin): UserAssetAccount {
            $account = $this->lockedAccount($user);
            $remaining = $points;
            $repaid = 0;
            PointDebt::query()->where('user_id', $user->id)->where('remaining_debt_points', '>', 0)->orderBy('id')->lockForUpdate()->get()
                ->each(function (PointDebt $debt) use (&$remaining, &$repaid): void {
                    if ($remaining === 0) {
                        return;
                    }
                    $paid = min($remaining, $debt->remaining_debt_points);
                    $left = $debt->remaining_debt_points - $paid;
                    $debt->update(['remaining_debt_points' => $left, 'status' => $left === 0 ? 'repaid' : 'partially_repaid', 'settled_at' => $left === 0 ? now() : null]);
                    $remaining -= $paid;
                    $repaid += $paid;
                });

            $batch = null;
            if ($remaining > 0) {
                $batch = PointBatch::query()->create([
                    'user_id' => $user->id, 'source_code' => $sourceCode, 'source_business_id' => $businessId,
                    'issued_points' => $remaining, 'remaining_points' => $remaining, 'status' => 'available',
                    'event_key_hash' => $this->fingerprint('batch:'.$hash), 'issued_at' => now(),
                    'expires_at' => $this->expiresAt($sourceCode),
                ]);
            }
            $account->update([
                'points_available' => $account->points_available + $remaining,
                'points_debt' => $account->points_debt - $repaid,
                'version' => $account->version + 1,
            ]);
            PointTransaction::query()->create([
                'user_id' => $user->id, 'point_batch_id' => $batch?->id, 'transaction_type' => 'grant',
                'change_available' => $remaining, 'change_debt' => -$repaid,
                'available_after' => $account->points_available, 'frozen_after' => $account->points_frozen, 'debt_after' => $account->points_debt,
                'source_code' => $sourceCode, 'source_business_id' => $businessId, 'event_key_hash' => $hash,
                'admin_user_id' => $admin?->id, 'reason' => $reason,
            ]);

            return $account->fresh();
        });
    }

    public function debitPoints(User $user, int $points, string $reason, string $eventKey, ?AdminUser $admin = null, string $type = 'adjust'): UserAssetAccount
    {
        $hash = $this->fingerprint('points:'.$eventKey);
        if (PointTransaction::query()->where('event_key_hash', $hash)->exists()) {
            return $this->account($user);
        }

        return DB::transaction(function () use ($user, $points, $reason, $hash, $admin, $type): UserAssetAccount {
            $account = $this->lockedAccount($user);
            if ($account->points_debt > 0) {
                throw ValidationException::withMessages(['points' => ['存在积分欠额时不能消费或减少积分。']]);
            }
            if ($account->points_available < $points) {
                throw ValidationException::withMessages(['points' => ['可用积分不足。']]);
            }
            $left = $points;
            $allocations = [];
            $batches = PointBatch::query()->where('user_id', $user->id)->where('status', 'available')->where('remaining_points', '>', 0)
                ->where('expires_at', '>', now())->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($batches as $batch) {
                if ($left === 0) {
                    break;
                }
                $used = min($left, $batch->remaining_points);
                $remaining = $batch->remaining_points - $used;
                $batch->update(['remaining_points' => $remaining, 'status' => $remaining === 0 ? 'exhausted' : 'available']);
                $allocations[] = [$batch->id, $used];
                $left -= $used;
            }
            if ($left !== 0) {
                throw ValidationException::withMessages(['points' => ['积分批次与账户汇总不一致，已停止扣减。']]);
            }
            $account->update(['points_available' => $account->points_available - $points, 'version' => $account->version + 1]);
            $transaction = PointTransaction::query()->create([
                'user_id' => $user->id, 'transaction_type' => $type, 'change_available' => -$points,
                'available_after' => $account->points_available, 'frozen_after' => $account->points_frozen, 'debt_after' => $account->points_debt,
                'event_key_hash' => $hash, 'admin_user_id' => $admin?->id, 'reason' => $reason,
            ]);
            foreach ($allocations as [$batchId, $used]) {
                DB::table('point_batch_allocations')->insert([
                    'public_id' => (string) Str::ulid(), 'point_transaction_id' => $transaction->id, 'point_batch_id' => $batchId,
                    'allocation_type' => 'consume', 'points' => $used, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $account->fresh();
        });
    }

    public function revokePointsWithDebt(User $user, int $points, string $sourceCode, string $businessId, string $reason, string $eventKey, ?AdminUser $admin = null): UserAssetAccount
    {
        $hash = $this->fingerprint('points:'.$eventKey);
        if (PointTransaction::query()->where('event_key_hash', $hash)->exists()) {
            return $this->account($user);
        }

        return DB::transaction(function () use ($user, $points, $sourceCode, $businessId, $reason, $eventKey, $hash, $admin) {
            $account = $this->lockedAccount($user);
            $deducted = min($points, $account->points_available);
            $debtPoints = $points - $deducted;
            $debt = null;
            if ($debtPoints > 0) {
                $debt = PointDebt::query()->create([
                    'user_id' => $user->id,
                    'source_code' => $sourceCode,
                    'source_business_id' => $businessId,
                    'original_debt_points' => $debtPoints,
                    'remaining_debt_points' => $debtPoints,
                    'status' => 'outstanding',
                    'reason' => $reason,
                    'event_key_hash' => $this->fingerprint('debt:'.$eventKey),
                ]);
            }
            $account->update([
                'points_available' => $account->points_available - $deducted,
                'points_debt' => $account->points_debt + $debtPoints,
                'version' => $account->version + 1,
            ]);
            PointTransaction::query()->create([
                'user_id' => $user->id,
                'point_debt_id' => $debt?->id,
                'transaction_type' => 'revoke',
                'change_available' => -$deducted,
                'change_debt' => $debtPoints,
                'available_after' => $account->points_available,
                'frozen_after' => $account->points_frozen,
                'debt_after' => $account->points_debt,
                'source_code' => $sourceCode,
                'source_business_id' => $businessId,
                'event_key_hash' => $hash,
                'admin_user_id' => $admin?->id,
                'reason' => $reason,
            ]);

            return $account->fresh();
        });
    }

    public function changeWallet(User $user, int $signedCents, string $reason, string $eventKey, ?AdminUser $admin = null, string $type = 'adjust'): UserAssetAccount
    {
        $hash = $this->fingerprint('wallet:'.$eventKey);
        if (WalletTransaction::query()->where('event_key_hash', $hash)->exists()) {
            return $this->account($user);
        }

        return DB::transaction(function () use ($user, $signedCents, $reason, $hash, $admin, $type): UserAssetAccount {
            $account = $this->lockedAccount($user);
            $after = $account->wallet_available_cents + $signedCents;
            if ($after < 0) {
                throw ValidationException::withMessages(['amount' => ['可用余额不足。']]);
            }
            $account->update(['wallet_available_cents' => $after, 'version' => $account->version + 1]);
            WalletTransaction::query()->create([
                'user_id' => $user->id, 'transaction_type' => $type, 'change_available_cents' => $signedCents,
                'available_after_cents' => $after, 'frozen_after_cents' => $account->wallet_frozen_cents,
                'event_key_hash' => $hash, 'admin_user_id' => $admin?->id, 'reason' => $reason,
            ]);

            return $account->fresh();
        });
    }

    public function freezeWallet(User $user, int $cents, string $reason, string $eventKey): UserAssetAccount
    {
        return $this->moveWallet($user, -$cents, $cents, 'freeze', $reason, $eventKey);
    }

    public function unfreezeWallet(User $user, int $cents, string $reason, string $eventKey, ?AdminUser $admin = null): UserAssetAccount
    {
        return $this->moveWallet($user, $cents, -$cents, 'unfreeze', $reason, $eventKey, $admin);
    }

    /** @return array<int,UserCoupon> */
    public function issueCoupons(User $user, CouponTemplate $template, int $quantity, string $reason, string $eventKey, ?AdminUser $admin = null): array
    {
        return DB::transaction(function () use ($user, $template, $quantity, $reason, $eventKey, $admin): array {
            $lockedTemplate = CouponTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($lockedTemplate->status !== 'active' || $lockedTemplate->issued_quantity + $quantity > $lockedTemplate->total_quantity) {
                throw ValidationException::withMessages(['coupon_template_id' => ['优惠券模板不可发行或剩余数量不足。']]);
            }
            $account = $this->lockedAccount($user);
            $issued = [];
            for ($index = 0; $index < $quantity; $index++) {
                $hash = $this->fingerprint('coupon:'.$eventKey.':'.$index);
                $existing = UserCoupon::query()->where('issue_event_key_hash', $hash)->first();
                if ($existing !== null) {
                    $issued[] = $existing;

                    continue;
                }
                $validFrom = CarbonImmutable::now();
                $coupon = UserCoupon::query()->create([
                    'user_id' => $user->id, 'coupon_template_id' => $lockedTemplate->id, 'status' => 'unused',
                    'template_snapshot' => $this->couponSnapshot($lockedTemplate), 'issue_event_key_hash' => $hash,
                    'valid_from' => $validFrom, 'valid_to' => $validFrom->addDays($lockedTemplate->valid_days)->endOfDay(),
                ]);
                CouponTransaction::query()->create([
                    'user_id' => $user->id, 'user_coupon_id' => $coupon->id, 'transaction_type' => 'issue',
                    'status_before' => null, 'status_after' => 'unused', 'event_key_hash' => $this->fingerprint('coupon-tx:'.$hash),
                    'admin_user_id' => $admin?->id, 'reason' => $reason,
                ]);
                $issued[] = $coupon;
            }
            $createdCount = count(array_filter($issued, fn (UserCoupon $coupon): bool => $coupon->wasRecentlyCreated));
            if ($createdCount > 0) {
                $lockedTemplate->update(['issued_quantity' => $lockedTemplate->issued_quantity + $createdCount, 'version' => $lockedTemplate->version + 1]);
                $account->update(['coupon_available' => $account->coupon_available + $createdCount, 'version' => $account->version + 1]);
            }

            return $issued;
        });
    }

    public function revokeCoupon(UserCoupon $coupon, string $reason, string $eventKey, AdminUser $admin): UserCoupon
    {
        $hash = $this->fingerprint('coupon-revoke:'.$eventKey);
        if (CouponTransaction::query()->where('event_key_hash', $hash)->exists()) {
            return $coupon->fresh();
        }

        return DB::transaction(function () use ($coupon, $reason, $hash, $admin): UserCoupon {
            $locked = UserCoupon::query()->lockForUpdate()->findOrFail($coupon->id);
            if (! in_array($locked->status, ['unused', 'returned'], true)) {
                throw ValidationException::withMessages(['coupon' => ['只有未使用或已返还的优惠券可以撤销。']]);
            }
            $before = $locked->status;
            $account = UserAssetAccount::query()->where('user_id', $locked->user_id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => 'revoked']);
            $account->update(['coupon_available' => $account->coupon_available - 1, 'version' => $account->version + 1]);
            CouponTransaction::query()->create([
                'user_id' => $locked->user_id, 'user_coupon_id' => $locked->id, 'transaction_type' => 'revoke',
                'status_before' => $before, 'status_after' => 'revoked', 'event_key_hash' => $hash,
                'admin_user_id' => $admin->id, 'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    public function account(User $user): UserAssetAccount
    {
        return UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->refresh();
    }

    private function lockedAccount(User $user): UserAssetAccount
    {
        UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);

        return UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    private function expiresAt(string $sourceCode): CarbonImmutable
    {
        $sourceMap = ['daily_check_in' => 'sign_in', 'community_post' => 'post', 'community_comment' => 'comment', 'profile_completed' => 'profile', 'referral_new_user' => 'referral', 'first_task' => 'first_task'];
        $days = (int) $this->settings->read('points.global_validity_days')['value'];
        $sourceDays = $this->settings->read('points.source_validity_days')['value'];
        if (isset($sourceMap[$sourceCode]) && is_array($sourceDays)) {
            $days = (int) ($sourceDays[$sourceMap[$sourceCode]] ?? $days);
        }

        return CarbonImmutable::now('Asia/Shanghai')->addDays($days)->endOfDay();
    }

    /** @return array<string,mixed> */
    private function couponSnapshot(CouponTemplate $template): array
    {
        return [
            'name' => $template->name, 'cash_discount_cents' => $template->cash_discount_cents,
            'points_discount' => $template->points_discount, 'minimum_cash_cents' => $template->minimum_cash_cents,
            'minimum_points' => $template->minimum_points, 'valid_days' => $template->valid_days, 'version' => $template->version,
        ];
    }

    private function moveWallet(User $user, int $availableChange, int $frozenChange, string $type, string $reason, string $eventKey, ?AdminUser $admin = null): UserAssetAccount
    {
        $hash = $this->fingerprint('wallet:'.$eventKey);
        if (WalletTransaction::query()->where('event_key_hash', $hash)->exists()) {
            return $this->account($user);
        }

        return DB::transaction(function () use ($user, $availableChange, $frozenChange, $type, $reason, $hash, $admin): UserAssetAccount {
            $account = $this->lockedAccount($user);
            $availableAfter = $account->wallet_available_cents + $availableChange;
            $frozenAfter = $account->wallet_frozen_cents + $frozenChange;
            if ($availableAfter < 0 || $frozenAfter < 0) {
                throw ValidationException::withMessages(['amount' => ['钱包可用余额或冻结余额不足。']]);
            }
            $account->update(['wallet_available_cents' => $availableAfter, 'wallet_frozen_cents' => $frozenAfter, 'version' => $account->version + 1]);
            WalletTransaction::query()->create([
                'user_id' => $user->id, 'transaction_type' => $type, 'change_available_cents' => $availableChange,
                'change_frozen_cents' => $frozenChange, 'available_after_cents' => $availableAfter, 'frozen_after_cents' => $frozenAfter,
                'event_key_hash' => $hash, 'admin_user_id' => $admin?->id, 'reason' => $reason,
            ]);

            return $account->fresh();
        });
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
