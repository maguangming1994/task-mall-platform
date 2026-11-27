<?php

namespace App\Modules\Store\Services;

use App\Modules\Campus\Models\Campus;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreSettlement;
use App\Modules\Store\Models\StoreSettlementItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreSettlementService
{
    public function __construct(private readonly StoreCampusService $campuses) {}

    /** @return array<string, mixed> */
    public function unbilled(Store $store, ?int $campusId = null): array
    {
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;
        $items = $this->pendingItems($store, $campusId, null, null);
        [$gross, $refunded, $commission, $net] = $this->totals($items, (int) $store->commission_rate_bps);

        return [
            'item_count' => $items->count(),
            'gross_amount' => $gross,
            'refunded_amount' => $refunded,
            'commission_amount' => $commission,
            'net_amount' => $net,
        ];
    }

    /** @return array<string, mixed> */
    public function preview(Store $store, string $periodStart, string $periodEnd, ?int $campusId = null): array
    {
        $this->assertPeriod($periodStart, $periodEnd);
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;
        $items = $this->pendingItems($store, $campusId, $periodStart, $periodEnd);
        [$gross, $refunded, $commission, $net] = $this->totals($items, (int) $store->commission_rate_bps);
        $campus = $campusId === null ? null : Campus::query()->find($campusId);

        return [
            'store_id' => $store->public_id,
            'campus_id' => $campus?->public_id,
            'campus_name' => $campus?->name,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'commission_rate_bps' => (int) $store->commission_rate_bps,
            'item_count' => $items->count(),
            'gross_amount' => $gross,
            'refunded_amount' => $refunded,
            'commission_amount' => $commission,
            'net_amount' => $net,
        ];
    }

    public function create(Store $store, string $periodStart, string $periodEnd, int $adminId, ?string $remark = null, ?int $campusId = null): StoreSettlement
    {
        $this->assertPeriod($periodStart, $periodEnd);
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;

        return DB::transaction(function () use ($store, $periodStart, $periodEnd, $adminId, $remark, $campusId): StoreSettlement {
            $overlap = StoreSettlement::query()
                ->where('store_id', $store->id)
                ->where('campus_id', $campusId)
                ->where('status', '!=', 'void')
                ->whereDate('period_start', '<=', $periodEnd)
                ->whereDate('period_end', '>=', $periodStart)
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['period' => ['该门店在此校区的结算周期内已有批次，请调整结算周期或切换校区。']]);
            }
            $items = $this->pendingItems($store, $campusId, $periodStart, $periodEnd, true);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['period' => ['所选周期内该校区没有待结算的门店订单。']]);
            }
            [$gross, $refunded, $commission, $net] = $this->totals($items, (int) $store->commission_rate_bps);
            $settlement = StoreSettlement::query()->create([
                'store_id' => $store->id,
                'campus_id' => $campusId,
                'batch_no' => 'ST'.now()->format('YmdHis').strtoupper(Str::random(5)),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_amount' => $gross,
                'refunded_amount' => $refunded,
                'commission_amount' => $commission,
                'net_amount' => $net,
                'item_count' => $items->count(),
                'status' => 'pending',
                'remark' => $remark,
                'created_by_admin_id' => $adminId,
            ]);
            foreach ($items as $item) {
                $netBase = max(0, (int) $item->paid_cash_amount - (int) $item->refunded_cash_amount);
                StoreSettlementItem::query()->create([
                    'store_settlement_id' => $settlement->id,
                    'order_item_id' => $item->id,
                    'order_id' => $item->order_id,
                    'gross_amount' => (int) $item->paid_cash_amount,
                    'refunded_amount' => (int) $item->refunded_cash_amount,
                    'commission_amount' => (int) round($netBase * (int) $store->commission_rate_bps / 10000),
                    'net_amount' => $netBase - (int) round($netBase * (int) $store->commission_rate_bps / 10000),
                ]);
            }

            return $settlement->fresh(['store']);
        });
    }

    public function confirm(StoreSettlement $settlement): StoreSettlement
    {
        return DB::transaction(function () use ($settlement): StoreSettlement {
            $locked = StoreSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['settlement' => ['只有待确认的结算批次可以确认。']]);
            }
            $locked->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            return $locked->fresh(['store']);
        });
    }

    public function markPaid(StoreSettlement $settlement): StoreSettlement
    {
        return DB::transaction(function () use ($settlement): StoreSettlement {
            $locked = StoreSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'confirmed') {
                throw ValidationException::withMessages(['settlement' => ['请先确认结算批次，再标记打款完成。']]);
            }
            $locked->update(['status' => 'paid', 'paid_at' => now()]);

            return $locked->fresh(['store']);
        });
    }

    /** @return array<string, mixed> */
    public function present(StoreSettlement $settlement, bool $withItems = false): array
    {
        $settlement->loadMissing(['store', 'campus']);
        $data = [
            'id' => $settlement->public_id,
            'batch_no' => $settlement->batch_no,
            'store' => $settlement->store === null ? null : ['id' => $settlement->store->public_id, 'name' => $settlement->store->name],
            'campus_id' => $settlement->campus?->public_id,
            'campus_name' => $settlement->campus?->name,
            'period_start' => $settlement->period_start?->toDateString(),
            'period_end' => $settlement->period_end?->toDateString(),
            'gross_amount' => (int) $settlement->gross_amount,
            'refunded_amount' => (int) $settlement->refunded_amount,
            'commission_amount' => (int) $settlement->commission_amount,
            'net_amount' => (int) $settlement->net_amount,
            'item_count' => (int) $settlement->item_count,
            'status' => $settlement->status,
            'remark' => $settlement->remark,
            'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
            'paid_at' => $settlement->paid_at?->toIso8601String(),
            'created_at' => $settlement->created_at?->toIso8601String(),
        ];
        if ($withItems) {
            $data['items'] = $settlement->items()->with('settlement')->get()->map(fn (StoreSettlementItem $item): array => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'order_id' => $item->order_id,
                'gross_amount' => (int) $item->gross_amount,
                'refunded_amount' => (int) $item->refunded_amount,
                'commission_amount' => (int) $item->commission_amount,
                'net_amount' => (int) $item->net_amount,
            ])->all();
        }

        return $data;
    }

    /** @return Collection<int, OrderItem> */
    private function pendingItems(Store $store, ?int $campusId, ?string $start, ?string $end, bool $lock = false): Collection
    {
        $settled = DB::table('store_settlement_items')->select('order_item_id');

        return OrderItem::query()
            ->where('store_id', $store->id)
            ->whereNotIn('id', $settled)
            ->whereHas('order', function ($query) use ($store, $campusId, $start, $end): void {
                $query->withoutGlobalScope('campus_context')
                    ->when($campusId !== null, fn ($inner) => $this->campuses->applyOrderCampus($inner, $store, $campusId))
                    ->whereNotNull('paid_at')
                    ->where('status', '!=', 'cancelled')
                    ->when($start !== null && $end !== null, fn ($inner) => $inner->whereBetween('paid_at', [$start.' 00:00:00', $end.' 23:59:59']));
            })
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function totals(Collection $items, int $rateBps): array
    {
        $gross = (int) $items->sum(fn (OrderItem $item) => (int) $item->paid_cash_amount);
        $refunded = (int) $items->sum(fn (OrderItem $item) => (int) $item->refunded_cash_amount);
        $netBase = max(0, $gross - $refunded);
        $commission = (int) round($netBase * $rateBps / 10000);

        return [$gross, $refunded, $commission, $netBase - $commission];
    }

    private function assertPeriod(string $start, string $end): void
    {
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false) {
            throw ValidationException::withMessages(['period' => ['请选择有效的结算周期。']]);
        }
        if ($start > $end) {
            throw ValidationException::withMessages(['period' => ['结算开始日期不能晚于结束日期。']]);
        }
    }
}
