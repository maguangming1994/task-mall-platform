<?php

namespace App\Modules\Store\Services;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Store\Models\Store;
use Illuminate\Pagination\LengthAwarePaginator;

class StoreOrderService
{
    public function __construct(private readonly StoreCampusService $campuses) {}

    /** @param array<string, mixed> $filters */
    public function paginate(Store $store, array $filters, ?int $campusId = null): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $size = min(50, max(1, (int) ($filters['page_size'] ?? 20)));
        $status = trim((string) ($filters['status'] ?? ''));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;

        return Order::query()
            // 门店订单按显式校区过滤，不能依赖店主个人校区上下文
            ->withoutGlobalScope('campus_context')
            ->when($campusId !== null, fn ($query) => $this->campuses->applyOrderCampus($query, $store, $campusId))
            ->whereHas('items', fn ($query) => $query->where('store_id', $store->id))
            ->with(['items' => fn ($query) => $query->where('store_id', $store->id)])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', fn ($query) => $query->where('order_no', 'like', "%{$keyword}%"))
            ->latest('id')
            ->paginate($size, ['*'], 'page', $page)
            ->through(fn (Order $order): array => $this->present($order));
    }

    /** @return array<string, mixed> */
    public function present(Order $order): array
    {
        $items = $order->items->map(fn (OrderItem $item): array => [
            'id' => $item->public_id,
            'product_name' => $item->product_name,
            'sku_name' => $item->sku_name,
            'sku_description' => collect($item->specs_snapshot)->map(fn ($value, $key) => sprintf('%s: %s', $key, $value))->implode(', '),
            'image_file_public_id' => $item->image_file_public_id,
            'quantity' => (int) $item->quantity,
            'unit_cash_amount' => (int) $item->unit_cash_amount,
            'paid_cash_amount' => (int) $item->paid_cash_amount,
            'refunded_cash_amount' => (int) $item->refunded_cash_amount,
            'refunded_quantity' => (int) $item->refunded_quantity,
            'fulfillment_type' => $item->fulfillment_type,
        ]);

        return [
            'id' => $order->public_id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'fulfillment_type' => $order->fulfillment_type,
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'store_gross_amount' => (int) $items->sum('paid_cash_amount'),
            'store_refunded_amount' => (int) $items->sum('refunded_cash_amount'),
            'shipping_amount' => (int) $order->shipping_amount,
            'items' => $items->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function overview(Store $store, ?int $campusId = null): array
    {
        $today = now()->startOfDay();
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;
        $base = Order::query()
            ->withoutGlobalScope('campus_context')
            ->when($campusId !== null, fn ($query) => $this->campuses->applyOrderCampus($query, $store, $campusId))
            ->whereHas('items', fn ($query) => $query->where('store_id', $store->id));

        $paidToday = (clone $base)->whereNotNull('paid_at')->where('paid_at', '>=', $today)->get(['id', 'paid_at']);
        $todayItemTotals = OrderItem::query()
            ->where('store_id', $store->id)
            ->whereIn('order_id', $paidToday->pluck('id'))
            ->selectRaw('COALESCE(SUM(paid_cash_amount), 0) AS paid, COALESCE(SUM(refunded_cash_amount), 0) AS refunded')
            ->first();

        $pendingFulfillment = (clone $base)->whereIn('status', ['pending_fulfillment', 'partially_refunded'])->count();
        $productCounts = $store->products()
            ->selectRaw("status, COUNT(*) AS total")
            ->groupBy('status')
            ->pluck('total', 'status');

        $lowStock = \App\Modules\Store\Models\StoreInventory::query()
            ->whereHas('sku.product', fn ($query) => $query->where('store_id', $store->id))
            ->when($campusId !== null, fn ($query) => $query->where('campus_id', $campusId))
            ->whereColumn('on_hand_quantity', '<=', 'reserved_quantity')
            ->count();

        $unbilled = app(StoreSettlementService::class)->unbilled($store, $campusId);

        return [
            'store' => [
                'id' => $store->public_id,
                'name' => $store->name,
                'logo_url' => $store->logo_url,
                'status' => $store->status,
                'commission_rate_bps' => (int) $store->commission_rate_bps,
            ],
            'today' => [
                'paid_order_count' => $paidToday->count(),
                'revenue_amount' => max(0, (int) ($todayItemTotals->paid ?? 0) - (int) ($todayItemTotals->refunded ?? 0)),
            ],
            'pending_fulfillment_count' => $pendingFulfillment,
            'product_counts' => [
                'total' => (int) $productCounts->sum(),
                'on_sale' => (int) ($productCounts['on_sale'] ?? 0),
                'draft' => (int) ($productCounts['draft'] ?? 0),
                'off_shelf' => (int) ($productCounts['off_shelf'] ?? 0),
            ],
            'low_stock_sku_count' => $lowStock,
            'unbilled' => $unbilled,
        ];
    }
}
