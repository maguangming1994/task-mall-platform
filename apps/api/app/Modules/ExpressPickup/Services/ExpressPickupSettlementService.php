<?php

namespace App\Modules\ExpressPickup\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\ExpressPickupSettlement;
use App\Modules\ExpressPickup\Models\ExpressPickupSettlementItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpressPickupSettlementService
{
    public function build(int $adminId, string $periodStart, string $periodEnd, ?int $campusId): ExpressPickupSettlement
    {
        return DB::transaction(function () use ($adminId, $periodStart, $periodEnd, $campusId): ExpressPickupSettlement {
            $query = ExpressPickupOrder::query()
                ->where('status', ExpressPickupOrder::STATUS_COMPLETED)
                ->whereNull('settled_at')
                ->whereNotNull('runner_user_id')
                ->whereBetween('completed_at', [$periodStart.' 00:00:00', $periodEnd.' 23:59:59']);
            if ($campusId !== null) {
                $query->where('campus_id', $campusId);
            }
            $orders = $query->lockForUpdate()->get();
            if ($orders->isEmpty()) {
                throw ValidationException::withMessages(['period' => ['所选周期内没有待结算的已完成代取单。']]);
            }

            $groups = $orders->groupBy('runner_user_id');
            $settlement = ExpressPickupSettlement::query()->create([
                'batch_no' => 'EPS'.now()->format('YmdHis').strtoupper(Str::random(5)),
                'campus_id' => $campusId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'draft',
                'total_cents' => (int) $orders->sum('reward_cents'),
                'runner_count' => $groups->count(),
                'order_count' => $orders->count(),
                'created_by_admin_id' => $adminId,
            ]);

            foreach ($groups as $runnerUserId => $runnerOrders) {
                ExpressPickupSettlementItem::query()->create([
                    'settlement_id' => $settlement->id,
                    'runner_user_id' => (int) $runnerUserId,
                    'order_count' => $runnerOrders->count(),
                    'amount_cents' => (int) $runnerOrders->sum('reward_cents'),
                    'status' => 'pending',
                ]);
            }

            ExpressPickupOrder::query()
                ->whereIn('id', $orders->pluck('id')->all())
                ->update(['settlement_id' => $settlement->id, 'settled_at' => now()]);

            return $settlement->fresh('items');
        });
    }

    public function confirm(ExpressPickupSettlement $settlement): ExpressPickupSettlement
    {
        return DB::transaction(function () use ($settlement): ExpressPickupSettlement {
            $locked = ExpressPickupSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['settlement' => ['只有草稿批次可以确认。']]);
            }
            $locked->forceFill(['status' => 'pending', 'confirmed_at' => now()])->save();

            return $locked->fresh('items');
        });
    }

    public function pay(int $adminId, ExpressPickupSettlement $settlement): ExpressPickupSettlement
    {
        return DB::transaction(function () use ($settlement, $adminId): ExpressPickupSettlement {
            $locked = ExpressPickupSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['settlement' => ['请先确认批次，再执行打款。']]);
            }
            $items = ExpressPickupSettlementItem::query()
                ->where('settlement_id', $locked->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();
            foreach ($items as $item) {
                $this->creditWallet((int) $item->runner_user_id, (int) $item->amount_cents, $locked, $adminId);
                $item->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            }
            $locked->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return $locked->fresh('items');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ExpressPickupSettlement $settlement): array
    {
        return [
            'id' => $settlement->public_id,
            'batch_no' => $settlement->batch_no,
            'campus_id' => $settlement->campus_id,
            'period_start' => $settlement->period_start?->toDateString(),
            'period_end' => $settlement->period_end?->toDateString(),
            'status' => $settlement->status,
            'total_cents' => $settlement->total_cents,
            'runner_count' => $settlement->runner_count,
            'order_count' => $settlement->order_count,
            'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
            'paid_at' => $settlement->paid_at?->toIso8601String(),
            'created_at' => $settlement->created_at?->toIso8601String(),
            'items' => $settlement->items->map(fn (ExpressPickupSettlementItem $item): array => [
                'id' => $item->id,
                'runner_user_id' => $item->runner_user_id,
                'order_count' => $item->order_count,
                'amount_cents' => $item->amount_cents,
                'status' => $item->status,
                'paid_at' => $item->paid_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function creditWallet(int $userId, int $amount, ExpressPickupSettlement $settlement, int $adminId): void
    {
        User::query()->lockForUpdate()->findOrFail($userId);
        UserAssetAccount::query()->firstOrCreate(['user_id' => $userId]);
        $account = UserAssetAccount::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
        $after = (int) $account->wallet_available_cents + $amount;
        $account->forceFill(['wallet_available_cents' => $after, 'version' => (int) $account->version + 1])->save();

        WalletTransaction::query()->create([
            'user_id' => $userId,
            'transaction_type' => 'credit',
            'change_available_cents' => $amount,
            'change_frozen_cents' => 0,
            'available_after_cents' => $after,
            'frozen_after_cents' => (int) $account->wallet_frozen_cents,
            'business_type' => 'express_pickup_settlement',
            'business_id' => $settlement->public_id,
            'event_key_hash' => hash('sha256', 'express-pickup-settlement:'.$settlement->public_id.':'.$userId),
            'admin_user_id' => $adminId,
            'reason' => '跑腿代取结算 '.$settlement->batch_no,
        ]);
    }
}
