<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Models\Order;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminOrderPresenter
{
    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @return Collection<int, array<string, mixed>>
     */
    public function presentMany(EloquentCollection $orders, bool $showSensitive = false, bool $includeDetails = false): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $orders->loadMissing(['user', 'items', 'fulfillments', 'refunds', 'exchanges']);
        $orderIds = $orders->modelKeys();
        $orderItemIds = $orders->pluck('items')->flatten()->pluck('id')->all();
        $fulfillmentIds = $orders->pluck('fulfillments')->flatten()->pluck('id')->all();

        $pendingRefunds = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->whereIn('refund_items.order_item_id', $orderItemIds)
            ->whereIn('refunds.status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing'])
            ->selectRaw('refund_items.order_item_id, SUM(refund_items.quantity) AS quantity')
            ->groupBy('refund_items.order_item_id')->pluck('quantity', 'refund_items.order_item_id');
        $activeExchanges = DB::table('exchanges')->whereIn('order_item_id', $orderItemIds)
            ->whereNotIn('status', ['rejected', 'closed', 'cancelled', 'completed'])
            ->selectRaw('order_item_id, SUM(quantity) AS quantity')
            ->groupBy('order_item_id')->pluck('quantity', 'order_item_id');

        $fulfilledByItem = DB::table('fulfillment_items')
            ->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')
            ->whereIn('fulfillments.order_id', $orderIds)
            ->groupBy('fulfillment_items.order_item_id')
            ->selectRaw('fulfillment_items.order_item_id, SUM(fulfillment_items.quantity) AS fulfilled_quantity')
            ->get()
            ->keyBy('order_item_id');

        $linesByFulfillment = collect();
        if ($fulfillmentIds !== []) {
            $linesByFulfillment = DB::table('fulfillment_items')
                ->join('order_items', 'order_items.id', '=', 'fulfillment_items.order_item_id')
                ->whereIn('fulfillment_items.fulfillment_id', $fulfillmentIds)
                ->get([
                    'fulfillment_items.fulfillment_id',
                    'order_items.public_id as order_item_id',
                    'fulfillment_items.quantity',
                ])
                ->groupBy('fulfillment_id');
        }

        $statusChanges = collect();
        if ($includeDetails) {
            $statusChanges = DB::table('order_status_changes')
                ->whereIn('order_id', $orderIds)
                ->orderBy('id')
                ->get()
                ->groupBy('order_id');
        }

        return $orders->map(fn (Order $order): array => $this->present(
            $order,
            $fulfilledByItem,
            $pendingRefunds,
            $activeExchanges,
            $linesByFulfillment,
            $showSensitive,
            $includeDetails ? collect($statusChanges->get($order->id, [])) : collect(),
            $includeDetails,
        ));
    }

    /** @return array<string, mixed> */
    private function present(
        Order $order,
        Collection $fulfilledByItem,
        Collection $pendingRefunds,
        Collection $activeExchanges,
        Collection $linesByFulfillment,
        bool $showSensitive,
        Collection $statusChanges,
        bool $includeDetails,
    ): array {
        $refunds = $order->refunds->map(fn ($refund): array => [
            'id' => $refund->public_id,
            'refund_no' => $refund->refund_no,
            'service_type' => $refund->service_type,
            'status' => $refund->status,
            'refund_wallet_amount' => (int) $refund->refund_wallet_amount,
            'refund_external_amount' => (int) $refund->refund_external_amount,
            'refund_points' => (int) $refund->refund_points,
            'responsibility_party' => $refund->responsibility_party,
            'created_at' => $refund->created_at?->toIso8601String(),
        ])->values();
        $exchanges = $order->exchanges->map(fn ($exchange): array => [
            'id' => $exchange->public_id,
            'exchange_no' => $exchange->exchange_no,
            'order_item_id' => $order->items->firstWhere('id', $exchange->order_item_id)?->public_id,
            'quantity' => (int) $exchange->quantity,
            'status' => $exchange->status,
            'responsibility_party' => $exchange->responsibility_party,
            'created_at' => $exchange->created_at?->toIso8601String(),
        ])->values();

        $data = [
            'id' => $order->public_id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'fulfillment_type' => $order->fulfillment_type,
            'cash_payment_method' => $order->cash_payment_method,
            'items_cash_amount' => (int) $order->items_cash_amount,
            'items_points' => (int) $order->items_points,
            'cash_discount_amount' => (int) $order->cash_discount_amount,
            'points_discount' => (int) $order->points_discount,
            'payable_cash_amount' => (int) $order->payable_cash_amount,
            'payable_points' => (int) $order->payable_points,
            'paid_wallet_amount' => (int) $order->paid_wallet_amount,
            'paid_external_amount' => (int) $order->paid_external_amount,
            'paid_points' => (int) $order->paid_points,
            'refunded_wallet_amount' => (int) $order->refunded_wallet_amount,
            'refunded_external_amount' => (int) $order->refunded_external_amount,
            'refunded_points' => (int) $order->refunded_points,
            'shipping_amount' => (int) $order->shipping_amount,
            'user' => [
                'id' => $order->user?->public_id,
                'nickname' => $order->user?->nickname,
                'phone_masked' => $this->maskPhone($order->user?->phone),
            ],
            'items' => $order->items->map(function ($item) use ($fulfilledByItem, $pendingRefunds, $activeExchanges): array {
                $fulfilled = (int) ($fulfilledByItem->get($item->id)?->fulfilled_quantity ?? 0);
                $remaining = max(0, (int) $item->quantity - (int) $item->refunded_quantity - $fulfilled);
                $pendingRefund = (int) ($pendingRefunds->get($item->id) ?? 0);
                $activeExchange = (int) ($activeExchanges->get($item->id) ?? 0);

                return [
                    'id' => $item->public_id,
                    'product_name' => $item->product_name,
                    'sku_name' => $item->sku_name,
                    'specs' => $item->specs_snapshot ?? [],
                    'quantity' => (int) $item->quantity,
                    'refunded_quantity' => (int) $item->refunded_quantity,
                    'pending_refund_quantity' => $pendingRefund,
                    'active_exchange_quantity' => $activeExchange,
                    'remaining_after_sale_quantity' => max(0, (int) $item->quantity - (int) $item->refunded_quantity - $pendingRefund - $activeExchange),
                    'fulfilled_quantity' => $fulfilled,
                    'remaining_fulfillment_quantity' => $remaining,
                    'purchase_mode' => $item->purchase_mode,
                    'fulfillment_type' => $item->fulfillment_type,
                ];
            })->values(),
            'fulfillments' => $order->fulfillments->map(fn ($fulfillment): array => [
                'id' => $fulfillment->public_id,
                'fulfillment_no' => $fulfillment->fulfillment_no,
                'method' => $fulfillment->method,
                'status' => $fulfillment->status,
                'carrier_code' => $fulfillment->carrier_code,
                'carrier_name' => $fulfillment->carrier_name,
                'tracking_no' => $fulfillment->tracking_no,
                'no_logistics_reason' => $fulfillment->no_logistics_reason,
                'virtual_delivery_configured' => $fulfillment->virtual_delivery_snapshot !== null,
                'items' => collect($linesByFulfillment->get($fulfillment->id, []))->map(fn ($line): array => [
                    'order_item_id' => $line->order_item_id,
                    'quantity' => (int) $line->quantity,
                ])->values(),
                'fulfilled_at' => $fulfillment->fulfilled_at?->toIso8601String(),
                'created_at' => $fulfillment->created_at?->toIso8601String(),
            ])->values(),
            'after_sale_summary' => [
                'refund_count' => $refunds->count(),
                'exchange_count' => $exchanges->count(),
                'active_count' => $refunds->whereNotIn('status', ['completed', 'rejected', 'closed'])->count()
                    + $exchanges->whereNotIn('status', ['completed', 'rejected', 'closed'])->count(),
                'refunds' => $refunds,
                'exchanges' => $exchanges,
            ],
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'fulfill_by' => $order->fulfill_by?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['address_snapshot'] = $this->presentAddress($order->address_snapshot, $showSensitive);
            $data['coupon_snapshot'] = $order->coupon_snapshot;
            $data['shipping_rule_snapshot'] = $order->shipping_rule_snapshot;
            $data['status_changes'] = $statusChanges->map(fn ($change): array => [
                'from_status' => $change->from_status,
                'to_status' => $change->to_status,
                'event' => $change->event,
                'reason' => $change->reason,
                'created_at' => $change->created_at,
            ])->values();
        }

        return $data;
    }

    /** @return array<string, mixed>|null */
    private function presentAddress(?array $address, bool $showSensitive): ?array
    {
        if ($address === null) {
            return null;
        }

        if ($showSensitive) {
            return $address;
        }

        $address['recipient_name'] = $this->maskName($address['recipient_name'] ?? null);
        $address['phone'] = $this->maskPhone($address['phone'] ?? null);

        return $address;
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return mb_strlen($phone) >= 7
            ? mb_substr($phone, 0, 3).'****'.mb_substr($phone, -4)
            : str_repeat('*', mb_strlen($phone));
    }

    private function maskName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return mb_substr($name, 0, 1).str_repeat('*', max(1, mb_strlen($name) - 1));
    }
}
