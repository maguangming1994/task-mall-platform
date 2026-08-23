<?php

namespace App\Modules\Refund\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Refund\Models\ReturnShippingCompensation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnShippingCompensationService
{
    public function __construct(private readonly AssetLedgerService $assets) {}

    public function review(AdminUser $admin, ReturnShippingCompensation $compensation, array $data, string $key): ReturnShippingCompensation
    {
        $hash = hash('sha256', $key);
        if ($found = ReturnShippingCompensation::query()->where('review_idempotency_key_hash', $hash)->first()) {
            if ($found->id !== $compensation->id) {
                throw ValidationException::withMessages(['idempotency_key' => '该幂等键已用于其他运费补偿审核，请更换后重试。']);
            }

            return $found;
        }

        return DB::transaction(function () use ($admin, $compensation, $data, $hash) {
            $locked = ReturnShippingCompensation::query()->with('user')->lockForUpdate()->findOrFail($compensation->id);
            if ($locked->review_idempotency_key_hash === $hash) {
                return $locked;
            }
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['compensation' => '该运费补偿已审核，不能重复处理。']);
            }
            if ($data['decision'] === 'reject') {
                $locked->update(['status' => 'rejected', 'approved_amount' => 0, 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'review_idempotency_key_hash' => $hash]);

                return $locked->fresh();
            }

            $approved = (int) ($data['approved_amount'] ?? $locked->requested_amount);
            if ($approved < 1 || $approved > $locked->requested_amount) {
                throw ValidationException::withMessages(['approved_amount' => '批准金额必须大于 0 且不能超过用户实际支付的退货运费。']);
            }
            $this->assets->changeWallet($locked->user, $approved, '退货运费补偿已审核并发放至余额。', 'return-shipping-compensation:'.$locked->public_id, $admin, 'shipping_compensation');
            $locked->update(['status' => 'paid', 'approved_amount' => $approved, 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['note'], 'review_idempotency_key_hash' => $hash, 'paid_at' => now()]);

            return $locked->fresh();
        });
    }
}
