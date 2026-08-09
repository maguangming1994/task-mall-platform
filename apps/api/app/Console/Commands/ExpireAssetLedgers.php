<?php

namespace App\Console\Commands;

use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\CouponTransaction;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Assets\Models\UserCoupon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireAssetLedgers extends Command
{
    protected $signature = 'assets:expire';

    protected $description = 'Expire due point batches and unused coupons idempotently';

    public function handle(): int
    {
        $points = 0;
        PointBatch::query()->where('status', 'available')->where('remaining_points', '>', 0)->where('expires_at', '<=', now())->orderBy('id')->each(function (PointBatch $batch) use (&$points): void {
            DB::transaction(function () use ($batch, &$points): void {
                $locked = PointBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($locked->status !== 'available' || $locked->remaining_points === 0 || $locked->expires_at->isFuture()) {
                    return;
                }
                $account = UserAssetAccount::query()->where('user_id', $locked->user_id)->lockForUpdate()->firstOrFail();
                $amount = $locked->remaining_points;
                if ($account->points_available < $amount) {
                    throw new \RuntimeException('Point batch and account summary mismatch; expiry stopped.');
                }
                $account->update(['points_available' => $account->points_available - $amount, 'version' => $account->version + 1]);
                $locked->update(['remaining_points' => 0, 'status' => 'expired']);
                PointTransaction::query()->create([
                    'user_id' => $locked->user_id, 'point_batch_id' => $locked->id, 'transaction_type' => 'expire',
                    'change_available' => -$amount, 'available_after' => $account->points_available,
                    'frozen_after' => $account->points_frozen, 'debt_after' => $account->points_debt,
                    'source_code' => $locked->source_code, 'source_business_id' => $locked->source_business_id,
                    'event_key_hash' => hash('sha256', 'point-expire:'.$locked->id), 'reason' => '积分批次到期。',
                ]);
                $points++;
            });
        });
        $coupons = 0;
        UserCoupon::query()->where('status', 'unused')->where('valid_to', '<=', now())->orderBy('id')->each(function (UserCoupon $coupon) use (&$coupons): void {
            DB::transaction(function () use ($coupon, &$coupons): void {
                $locked = UserCoupon::query()->lockForUpdate()->findOrFail($coupon->id);
                if ($locked->status !== 'unused' || $locked->valid_to->isFuture()) {
                    return;
                }
                $account = UserAssetAccount::query()->where('user_id', $locked->user_id)->lockForUpdate()->firstOrFail();
                if ($account->coupon_available < 1) {
                    throw new \RuntimeException('Coupon instance and account summary mismatch; expiry stopped.');
                }
                $locked->update(['status' => 'expired']);
                $account->update(['coupon_available' => $account->coupon_available - 1, 'version' => $account->version + 1]);
                CouponTransaction::query()->create([
                    'user_id' => $locked->user_id, 'user_coupon_id' => $locked->id, 'transaction_type' => 'expire',
                    'status_before' => 'unused', 'status_after' => 'expired', 'event_key_hash' => hash('sha256', 'coupon-expire:'.$locked->id),
                    'reason' => '优惠券到期。',
                ]);
                $coupons++;
            });
        });
        $this->info("Expired point batches: {$points}; coupons: {$coupons}");

        return self::SUCCESS;
    }
}
