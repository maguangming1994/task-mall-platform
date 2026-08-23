<?php

namespace App\Modules\Order\Services;

use App\Common\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\CouponTransaction;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Order\Models\CheckoutPreview;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderBatch;
use App\Modules\Payment\Services\PaymentCapabilityService;
use App\Modules\Product\Models\ProductSku;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutOrderService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PaymentCapabilityService $paymentCapabilities,
    ) {}

    public function preview(User $user, array $request, string $platform): array
    {
        $request = $this->canonicalRequest($request);
        $pricing = $this->price($user, $request, $platform);
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(5);
        CheckoutPreview::query()->create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
            'request_hash' => $this->hash($request), 'request_snapshot' => $request,
            'pricing_snapshot' => $pricing, 'expires_at' => $expiresAt,
        ]);

        return ['preview_token' => $token, 'capability_version' => 1, ...$this->publicPricing($user, $pricing), 'expires_at' => $expiresAt->toIso8601String()];
    }

    public function create(User $user, array $request, string $platform, string $idempotencyKey): OrderBatch
    {
        $token = (string) ($request['preview_token'] ?? '');
        $request = $this->canonicalRequest($request);
        $idempotencyHash = $this->idempotencyKeyHash($user->id, 'checkout.create', $idempotencyKey);
        $legacyIdempotencyHash = hash('sha256', $idempotencyKey);
        $requestFingerprint = $this->requestFingerprint($request, $platform);
        if (strlen($token) !== 64) {
            throw ValidationException::withMessages(['preview_token' => '预结算令牌无效。']);
        }

        return DB::transaction(function () use ($user, $request, $platform, $token, $idempotencyHash, $legacyIdempotencyHash, $requestFingerprint) {
            // Serialize retries for the same actor before checking the unique
            // idempotency key. This also makes first-request races deterministic.
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = $this->existingBatch($user, $idempotencyHash, $legacyIdempotencyHash);
            if ($existing !== null) {
                $this->assertBatchFingerprint($existing, $requestFingerprint);

                return $existing->fresh();
            }

            $preview = CheckoutPreview::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $preview || $preview->user_id !== $user->id || $preview->consumed_at || $preview->expires_at->isPast()) {
                throw ValidationException::withMessages(['preview_token' => '预结算已失效，请重新预览。']);
            }
            if (! hash_equals($preview->request_hash, $this->hash($request))) {
                throw ValidationException::withMessages(['preview_token' => '下单内容与预结算不一致。']);
            }
            $pricing = $this->price($user, $request, $platform);
            if (! hash_equals($this->hash($preview->pricing_snapshot), $this->hash($pricing))) {
                throw ValidationException::withMessages(['checkout' => '价格、库存、优惠或运费规则已变化，请重新预览。']);
            }
            $batch = OrderBatch::query()->create([
                'batch_no' => $this->number('B'), 'user_id' => $user->id, 'client_platform' => $platform,
                'capability_version' => 1, 'status' => 'active', 'idempotency_key_hash' => $idempotencyHash,
                'request_fingerprint_hash' => $requestFingerprint,
                'checkout_preview_id' => $preview->id, ...$this->totals($pricing),
            ]);
            foreach ($pricing['groups'] as $group) {
                $this->createOrder($user, $batch, $group);
            }
            $preview->update(['consumed_at' => now()]);

            return $batch->fresh('orders.items');
        });
    }

    public function presentBatch(OrderBatch $batch): array
    {
        $batch->loadMissing('orders.items');

        return [
            'id' => $batch->public_id, 'batch_no' => $batch->batch_no, 'status' => $batch->status,
            ...collect($batch->only(['items_cash_amount', 'items_points', 'cash_discount_amount', 'points_discount', 'shipping_amount', 'payable_cash_amount', 'payable_points']))->map(fn ($value) => (int) $value)->all(),
            'orders' => $batch->orders->map(fn ($order) => $this->presentOrder($order)), 'created_at' => $batch->created_at->toIso8601String(),
        ];
    }

    public function presentOrder(Order $order): array
    {
        $order->loadMissing(['items', 'fulfillments']);
        $itemIds = $order->items->pluck('id');
        $pendingRefunds = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->whereIn('refund_items.order_item_id', $itemIds)
            ->whereIn('refunds.status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing'])
            ->selectRaw('refund_items.order_item_id, SUM(refund_items.quantity) AS quantity, SUM(refund_items.refund_cash_amount) AS cash_amount, SUM(refund_items.refund_points) AS points')
            ->groupBy('refund_items.order_item_id')->get()->keyBy('order_item_id');
        $activeExchanges = DB::table('exchanges')->whereIn('order_item_id', $itemIds)
            ->whereNotIn('status', ['rejected', 'closed', 'cancelled'])
            ->selectRaw('order_item_id, SUM(quantity) AS quantity')->groupBy('order_item_id')->pluck('quantity', 'order_item_id');
        $allRefundReservations = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->whereIn('refund_items.order_item_id', $itemIds)
            ->whereIn('refunds.status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing', 'succeeded'])
            ->selectRaw('refund_items.order_item_id, SUM(refund_items.quantity) AS quantity')
            ->groupBy('refund_items.order_item_id')->pluck('quantity', 'order_item_id');

        return [
            'id' => $order->public_id, 'order_batch_id' => $order->batch?->public_id ?? $order->batch()->value('public_id'),
            'order_no' => $order->order_no, 'fulfillment_type' => $order->fulfillment_type, 'status' => $order->status,
            'cash_payment_method' => $order->cash_payment_method, 'address' => $order->address_snapshot,
            'items_cash_amount' => $order->items_cash_amount, 'items_points' => $order->items_points,
            'cash_discount_amount' => $order->cash_discount_amount, 'points_discount' => $order->points_discount,
            'shipping_amount' => $order->shipping_amount, 'shipping_breakdown' => $order->shipping_rule_snapshot,
            'payable_cash_amount' => $order->payable_cash_amount, 'payable_points' => $order->payable_points,
            'paid_wallet_amount' => $order->paid_wallet_amount, 'paid_external_amount' => $order->paid_external_amount, 'paid_points' => $order->paid_points,
            'items' => $order->items->map(function ($item) use ($order, $pendingRefunds, $activeExchanges, $allRefundReservations) {
                $pending = $pendingRefunds->get($item->id);
                $pendingQuantity = (int) ($pending->quantity ?? 0);
                $activeExchangeQuantity = (int) ($activeExchanges->get($item->id) ?? 0);
                $remainingRefundableQuantity = max(0, (int) $item->quantity - (int) $item->refunded_quantity - $pendingQuantity - $activeExchangeQuantity);
                $remainingCash = max(0, (int) $item->paid_cash_amount - (int) $item->refunded_cash_amount - (int) ($pending->cash_amount ?? 0));
                $remainingPoints = max(0, (int) $item->paid_points - (int) $item->refunded_points - (int) ($pending->points ?? 0));
                $refundableCash = $activeExchangeQuantity === 0
                    ? $remainingCash
                    : min($remainingCash, intdiv((int) $item->paid_cash_amount * $remainingRefundableQuantity, (int) $item->quantity));
                $refundablePoints = $activeExchangeQuantity === 0
                    ? $remainingPoints
                    : min($remainingPoints, intdiv((int) $item->paid_points * $remainingRefundableQuantity, (int) $item->quantity));
                $refundStatusAllowed = in_array($order->status, ['pending_fulfillment', 'fulfilled', 'completed', 'partially_refunded'], true);
                $exchangeStatusAllowed = in_array($order->status, ['fulfilled', 'completed'], true);
                $serviceTypes = [];
                if ($refundStatusAllowed && $remainingRefundableQuantity > 0) {
                    $serviceTypes[] = 'refund_only';
                    if ($order->shipped_at !== null) {
                        $serviceTypes[] = 'return_and_refund';
                    }
                }
                if ($exchangeStatusAllowed && max(0, (int) $item->quantity - (int) ($allRefundReservations->get($item->id) ?? 0) - $activeExchangeQuantity) > 0) {
                    $serviceTypes[] = 'exchange';
                }
                $returnMethod = $item->fulfillment_type === 'logistics' ? 'logistics' : 'no_logistics';
                $hasActiveAfterSale = $pendingQuantity > 0 || $activeExchangeQuantity > 0;
                $afterSaleSummary = match (true) {
                    $serviceTypes !== [] && $hasActiveAfterSale => '部分数量售后处理中，剩余数量仍可申请',
                    $serviceTypes !== [] && $order->shipped_at === null => '未发货可申请仅退款，无需填写退货物流',
                    $serviceTypes !== [] && $returnMethod === 'no_logistics' => '支持仅退款、退货退款或换货，商品无需寄回物流',
                    $serviceTypes !== [] => '支持按剩余数量申请退款、退货退款或换货',
                    $hasActiveAfterSale => '当前数量已进入售后处理',
                    ! $refundStatusAllowed => '订单当前状态暂不支持售后申请',
                    default => '该商品已无剩余可售后数量',
                };

                return [
                    'id' => $item->public_id, 'product_name' => $item->product_name, 'sku_name' => $item->sku_name,
                    'sku_description' => collect($item->specs_snapshot)
                        ->map(fn ($value, $key) => sprintf('%s: %s', $key, $value))
                        ->implode(', '),
                    'purchase_mode' => $item->purchase_mode, 'fulfillment_type' => $item->fulfillment_type,
                    'quantity' => $item->quantity, 'unit_cash_amount' => $item->unit_cash_amount, 'unit_points' => $item->unit_points,
                    'paid_cash_amount' => $item->paid_cash_amount, 'paid_points' => $item->paid_points,
                    'unit_paid_cash_amount' => intdiv((int) $item->paid_cash_amount, (int) $item->quantity),
                    'unit_paid_points' => intdiv((int) $item->paid_points, (int) $item->quantity),
                    'refunded_quantity' => (int) $item->refunded_quantity,
                    'pending_refund_quantity' => $pendingQuantity,
                    'active_exchange_quantity' => $activeExchangeQuantity,
                    'remaining_refundable_quantity' => $remainingRefundableQuantity,
                    'remaining_exchangeable_quantity' => max(0, (int) $item->quantity - (int) ($allRefundReservations->get($item->id) ?? 0) - $activeExchangeQuantity),
                    'refundable_cash_amount' => $refundableCash,
                    'refundable_points' => $refundablePoints,
                    'after_sale_workflow' => [
                        'allowed' => $serviceTypes !== [],
                        'available_service_types' => $serviceTypes,
                        'recommended_service_type' => $serviceTypes[0] ?? null,
                        'return_method' => $returnMethod,
                        'requires_return_logistics' => in_array('return_and_refund', $serviceTypes, true) && $returnMethod === 'logistics',
                        'has_active_after_sale' => $hasActiveAfterSale,
                        'summary' => $afterSaleSummary,
                    ],
                ];
            }),
            'fulfillments' => $order->fulfillments->map(fn ($fulfillment) => [
                'id' => $fulfillment->public_id,
                'fulfillment_no' => $fulfillment->fulfillment_no,
                'method' => $fulfillment->method,
                'status' => $fulfillment->status,
                'carrier_code' => $fulfillment->carrier_code,
                'carrier_name' => $fulfillment->carrier_name,
                'tracking_no' => $fulfillment->tracking_no,
                'no_logistics_reason' => $fulfillment->no_logistics_reason,
                'virtual_delivery' => $fulfillment->virtual_delivery_snapshot,
                'fulfilled_at' => $fulfillment->fulfilled_at?->toIso8601String(),
            ]),
            'auto_complete_at' => $order->auto_complete_at?->toIso8601String(), 'completed_at' => $order->completed_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(), 'created_at' => $order->created_at->toIso8601String(),
        ];
    }

    private function price(User $user, array $request, string $platform): array
    {
        if ((int) $request['capability_version'] !== 1) {
            throw ValidationException::withMessages(['capability_version' => '客户端能力版本已过期。']);
        }
        $options = collect($request['group_options'])->keyBy('fulfillment_type');
        if ($options->count() !== count($request['group_options'])) {
            throw ValidationException::withMessages(['group_options' => '每种交付类型只能配置一次。']);
        }
        $seen = [];
        $items = collect($request['items'])->map(function ($input) use (&$seen) {
            $key = $input['sku_id'].':'.$input['purchase_mode'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['items' => '同一 SKU 和购买方式不能重复，请合并数量。']);
            }
            $seen[$key] = true;
            $sku = ProductSku::query()->where('public_id', $input['sku_id'])->with(['product', 'purchaseOptions', 'inventory', 'shippingTemplate.versions'])->firstOrFail();
            if ($sku->product->status !== 'on_sale' || $sku->status !== 'active') {
                throw ValidationException::withMessages(['items' => '商品或 SKU 已下架。']);
            }
            $option = $sku->purchaseOptions->firstWhere('purchase_mode', $input['purchase_mode']);
            if (! $option || ! $option->enabled || ($option->starts_at && $option->starts_at->isFuture()) || ($option->ends_at && $option->ends_at->isPast())) {
                throw ValidationException::withMessages(['items' => '购买方式当前不可用。']);
            }
            $quantity = (int) $input['quantity'];
            if ($sku->limit_per_order && $quantity > $sku->limit_per_order) {
                throw ValidationException::withMessages(['items' => '数量超过单笔限购。']);
            }
            if (! $sku->inventory || $sku->inventory->on_hand_quantity - $sku->inventory->reserved_quantity < $quantity) {
                throw ValidationException::withMessages(['items' => '可售库存不足。']);
            }

            return ['sku' => $sku, 'option' => $option, 'quantity' => $quantity, 'cash_subtotal' => $option->cash_amount * $quantity, 'points_subtotal' => $option->points * $quantity];
        });

        $couponIds = array_values(array_filter(array_column($request['group_options'], 'coupon_id')));
        if (count($couponIds) !== count(array_unique($couponIds))) {
            throw ValidationException::withMessages(['group_options' => '同一张优惠券不能用于多个拆分订单。']);
        }
        $paymentCapabilities = $this->paymentCapabilities->forPlatform($platform);
        $groups = $items->groupBy(fn ($item) => $item['sku']->fulfillment_type)->map(function (Collection $groupItems, string $fulfillment) use ($user, $options, $paymentCapabilities) {
            $groupOption = $options->get($fulfillment);
            if (! $groupOption) {
                throw ValidationException::withMessages(['group_options' => "缺少 {$fulfillment} 分组配置。"]);
            }
            $address = null;
            if ($fulfillment === 'logistics') {
                if (empty($groupOption['address_id'])) {
                    throw ValidationException::withMessages(['group_options' => '物流订单必须选择收货地址。']);
                }
                $address = UserAddress::query()->where('public_id', $groupOption['address_id'])->where('user_id', $user->id)->firstOrFail();
            }
            $cash = $groupItems->sum('cash_subtotal');
            $points = $groupItems->sum('points_subtotal');
            [$coupon, $cashDiscount, $pointsDiscount] = $this->coupon($user, $groupOption['coupon_id'] ?? null, $cash, $points);
            $shipping = $fulfillment === 'logistics' ? $this->shipping($address, $groupItems, $cash) : ['amount' => 0, 'groups' => []];
            $payableCash = max(0, $cash - $cashDiscount) + $shipping['amount'];
            $payablePoints = max(0, $points - $pointsDiscount);
            $method = $payableCash > 0 ? ($groupOption['cash_payment_method'] ?? null) : 'none';
            if ($payableCash > 0 && ! in_array($method, ['wallet', 'wechat'], true)) {
                throw ValidationException::withMessages(['group_options' => '有现金应付时必须选择余额全额或微信全额支付。']);
            }
            if ($method === 'wallet') {
                if (! $paymentCapabilities['wallet']['enabled']) {
                    throw ValidationException::withMessages(['cash_payment_method' => $paymentCapabilities['wallet']['reason'] ?? '商城余额支付当前不可用。']);
                }
                $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);
                if ($account->wallet_available_cents < $payableCash) {
                    throw ValidationException::withMessages(['cash_payment_method' => '余额不足，不能选择余额全额支付。']);
                }
            }
            if ($method === 'wechat' && ! $paymentCapabilities['wechat']['enabled']) {
                throw ValidationException::withMessages(['cash_payment_method' => $paymentCapabilities['wechat']['reason'] ?? '微信支付当前不可用。']);
            }

            return [
                'group_key' => $fulfillment, 'fulfillment_type' => $fulfillment, 'items' => $groupItems->values()->all(),
                'address' => $address, 'coupon' => $coupon, 'items_cash_amount' => $cash, 'items_points' => $points,
                'cash_discount_amount' => $cashDiscount, 'points_discount' => $pointsDiscount,
                'shipping_amount' => $shipping['amount'], 'shipping_breakdown' => $shipping['groups'],
                'payable_cash_amount' => $payableCash, 'payable_points' => $payablePoints, 'cash_payment_method' => $method,
            ];
        })->values();
        if ($groups->count() !== $options->count()) {
            throw ValidationException::withMessages(['group_options' => '存在没有商品的交付分组。']);
        }
        $cashMethods = $groups->where('payable_cash_amount', '>', 0)->pluck('cash_payment_method')->unique()->values();
        if ($cashMethods->count() > 1) {
            throw ValidationException::withMessages(['cash_payment_method' => '同一批订单只能统一选择余额全额支付或微信全额支付，不能混合使用。']);
        }
        if ($cashMethods->first() === 'wallet') {
            $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);
            if ($account->wallet_available_cents < $groups->sum('payable_cash_amount')) {
                throw ValidationException::withMessages(['cash_payment_method' => '余额不足，必须覆盖本批全部订单的现金应付金额。']);
            }
        }
        $totalPoints = $groups->sum('payable_points');
        $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id]);
        if ($account->points_debt > 0 || $account->points_available < $totalPoints) {
            throw ValidationException::withMessages(['points' => '可用积分不足或存在欠额。']);
        }

        return ['groups' => $groups->all(), ...$this->sumGroups($groups)];
    }

    private function createOrder(User $user, OrderBatch $batch, array $group): void
    {
        $pendingCash = $group['payable_cash_amount'] > 0;
        $expiry = $pendingCash ? now()->addMinutes((int) $this->settings->read('orders.unpaid_close_minutes')['value']) : null;
        $order = Order::query()->create([
            'order_no' => $this->number('O'), 'order_batch_id' => $batch->id, 'user_id' => $user->id,
            'fulfillment_type' => $group['fulfillment_type'], 'status' => $pendingCash ? 'pending_payment' : 'pending_fulfillment',
            'cash_payment_method' => $group['cash_payment_method'], 'address_snapshot' => $group['address'] ? $this->addressSnapshot($group['address']) : null,
            'user_coupon_id' => $group['coupon']?->id, 'coupon_snapshot' => $group['coupon']?->template_snapshot,
            'shipping_rule_snapshot' => $group['shipping_breakdown'], ...$this->groupTotals($group), 'expires_at' => $expiry,
        ]);
        $cashAllocations = $this->allocate($group['items'], $group['cash_discount_amount'], 'cash_subtotal');
        $pointsAllocations = $this->allocate($group['items'], $group['points_discount'], 'points_subtotal');
        $shippingAllocations = $this->allocate($group['items'], $group['shipping_amount'], 'quantity');
        foreach ($group['items'] as $index => $priced) {
            $sku = $priced['sku'];
            $item = $order->items()->create([
                'product_sku_id' => $sku->id, 'product_public_id' => $sku->product->public_id, 'sku_public_id' => $sku->public_id,
                'product_name' => $sku->product->name, 'sku_name' => $sku->name, 'specs_snapshot' => $sku->specs,
                'image_file_public_id' => $sku->image_file_public_id, 'purchase_mode' => $priced['option']->purchase_mode,
                'fulfillment_type' => $sku->fulfillment_type, 'quantity' => $priced['quantity'],
                'unit_cash_amount' => $priced['option']->cash_amount, 'unit_points' => $priced['option']->points,
                'cash_subtotal' => $priced['cash_subtotal'], 'points_subtotal' => $priced['points_subtotal'],
                'cash_discount_allocated' => $cashAllocations[$index], 'points_discount_allocated' => $pointsAllocations[$index],
                'shipping_allocated' => $shippingAllocations[$index], 'purchase_limit_snapshot' => ['per_order' => $sku->limit_per_order, 'per_day' => $sku->limit_per_day, 'campaign' => $sku->limit_campaign, 'lifetime' => $sku->limit_lifetime],
            ]);
            $this->reserveInventory($item, $sku->inventory, $priced['quantity'], $expiry);
        }
        $pointReservation = $group['payable_points'] > 0 ? $this->freezePoints($user, $order, $group['payable_points']) : null;
        if ($group['coupon']) {
            $this->lockCoupon($user, $order, $group['coupon']);
        }
        DB::table('order_status_changes')->insert(['order_id' => $order->id, 'from_status' => null, 'to_status' => $order->status, 'event' => 'created', 'reason' => '结算校验通过后创建拆分订单。', 'created_at' => now(), 'updated_at' => now()]);
        if (! $pendingCash) {
            $this->completeNonCashOrder($user, $order, $pointReservation);
        }
    }

    private function reserveInventory($orderItem, Inventory $inventory, int $quantity, $expiry): void
    {
        $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);
        if ($locked->on_hand_quantity - $locked->reserved_quantity < $quantity) {
            throw ValidationException::withMessages(['inventory' => '创建订单时库存已不足。']);
        }
        $beforeReserved = $locked->reserved_quantity;
        $locked->update(['reserved_quantity' => $beforeReserved + $quantity, 'version' => $locked->version + 1]);
        DB::table('inventory_reservations')->insert(['public_id' => (string) Str::ulid(), 'order_item_id' => $orderItem->id, 'inventory_id' => $locked->id, 'quantity' => $quantity, 'status' => 'reserved', 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);
        InventoryTransaction::query()->create(['inventory_id' => $locked->id, 'transaction_type' => 'reserve', 'on_hand_change' => 0, 'reserved_change' => $quantity, 'on_hand_before' => $locked->on_hand_quantity, 'on_hand_after' => $locked->on_hand_quantity, 'reserved_before' => $beforeReserved, 'reserved_after' => $locked->reserved_quantity, 'business_type' => 'order_item', 'business_id' => $orderItem->public_id, 'reason' => '创建待支付订单预占库存。', 'idempotency_key_hash' => hash('sha256', 'order-reserve:'.$orderItem->public_id)]);
    }

    private function freezePoints(User $user, Order $order, int $points): object
    {
        $account = UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        if ($account->points_available < $points || $account->points_debt > 0) {
            throw ValidationException::withMessages(['points' => '创建订单时可用积分不足。']);
        }
        $reservationId = DB::table('order_point_reservations')->insertGetId(['public_id' => (string) Str::ulid(), 'order_id' => $order->id, 'points' => $points, 'status' => 'frozen', 'created_at' => now(), 'updated_at' => now()]);
        $left = $points;
        foreach (PointBatch::query()->where('user_id', $user->id)->where('status', 'available')->where('remaining_points', '>', 0)->where('expires_at', '>', now())->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get() as $batch) {
            if ($left === 0) {
                break;
            }
            $used = min($left, $batch->remaining_points);
            $batch->update(['remaining_points' => $batch->remaining_points - $used, 'frozen_points' => $batch->frozen_points + $used, 'status' => $batch->remaining_points - $used === 0 ? 'frozen' : 'available']);
            DB::table('order_point_reservation_allocations')->insert(['order_point_reservation_id' => $reservationId, 'point_batch_id' => $batch->id, 'points' => $used, 'created_at' => now(), 'updated_at' => now()]);
            $left -= $used;
        }
        if ($left !== 0) {
            throw ValidationException::withMessages(['points' => '积分批次与账户汇总不一致。']);
        }
        $account->update(['points_available' => $account->points_available - $points, 'points_frozen' => $account->points_frozen + $points, 'version' => $account->version + 1]);

        return DB::table('order_point_reservations')->find($reservationId);
    }

    private function lockCoupon(User $user, Order $order, UserCoupon $coupon): void
    {
        $locked = UserCoupon::query()->lockForUpdate()->findOrFail($coupon->id);
        if ($locked->user_id !== $user->id || $locked->status !== 'unused' || $locked->valid_to->isPast()) {
            throw ValidationException::withMessages(['coupon_id' => '优惠券已不可用。']);
        }
        $locked->update(['status' => 'locked']);
        $account = UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $account->update(['coupon_available' => max(0, $account->coupon_available - 1), 'version' => $account->version + 1]);
        CouponTransaction::query()->create(['user_id' => $user->id, 'user_coupon_id' => $locked->id, 'transaction_type' => 'lock', 'status_before' => 'unused', 'status_after' => 'locked', 'business_type' => 'order', 'business_id' => $order->public_id, 'event_key_hash' => hash('sha256', 'order-coupon-lock:'.$order->public_id), 'reason' => '创建订单锁定优惠券。']);
    }

    private function completeNonCashOrder(User $user, Order $order, ?object $pointReservation): void
    {
        foreach ($order->items as $item) {
            $reservation = DB::table('inventory_reservations')->where('order_item_id', $item->id)->lockForUpdate()->first();
            $inventory = Inventory::query()->lockForUpdate()->findOrFail($reservation->inventory_id);
            $beforeOnHand = $inventory->on_hand_quantity;
            $beforeReserved = $inventory->reserved_quantity;
            $inventory->update(['on_hand_quantity' => $beforeOnHand - $reservation->quantity, 'reserved_quantity' => $beforeReserved - $reservation->quantity, 'version' => $inventory->version + 1]);
            DB::table('inventory_reservations')->where('id', $reservation->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
            InventoryTransaction::query()->create(['inventory_id' => $inventory->id, 'transaction_type' => 'sale_consume', 'on_hand_change' => -$reservation->quantity, 'reserved_change' => -$reservation->quantity, 'on_hand_before' => $beforeOnHand, 'on_hand_after' => $inventory->on_hand_quantity, 'reserved_before' => $beforeReserved, 'reserved_after' => $inventory->reserved_quantity, 'business_type' => 'order_item', 'business_id' => $item->public_id, 'reason' => '非现金订单创建成功后正式扣减库存。', 'idempotency_key_hash' => hash('sha256', 'order-consume:'.$item->public_id)]);
            $item->update(['paid_cash_amount' => 0, 'paid_points' => $item->points_subtotal - $item->points_discount_allocated]);
        }
        if ($pointReservation) {
            $account = UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            foreach (DB::table('order_point_reservation_allocations')->where('order_point_reservation_id', $pointReservation->id)->get() as $allocation) {
                $batch = PointBatch::query()->lockForUpdate()->findOrFail($allocation->point_batch_id);
                $batch->update(['frozen_points' => $batch->frozen_points - $allocation->points, 'status' => $batch->remaining_points === 0 && $batch->frozen_points - $allocation->points === 0 ? 'exhausted' : 'available']);
            }
            $account->update(['points_frozen' => $account->points_frozen - $pointReservation->points, 'version' => $account->version + 1]);
            PointTransaction::query()->create(['user_id' => $user->id, 'transaction_type' => 'order_consume', 'change_available' => 0, 'change_frozen' => -$pointReservation->points, 'available_after' => $account->points_available, 'frozen_after' => $account->points_frozen, 'debt_after' => $account->points_debt, 'source_code' => 'order', 'source_business_id' => $order->public_id, 'event_key_hash' => hash('sha256', 'order-points-consume:'.$order->public_id), 'reason' => '非现金订单创建成功后扣除冻结积分。']);
            DB::table('order_point_reservations')->where('id', $pointReservation->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
        }
        if ($order->user_coupon_id) {
            $coupon = UserCoupon::query()->lockForUpdate()->findOrFail($order->user_coupon_id);
            $coupon->update(['status' => 'used', 'used_at' => now()]);
            CouponTransaction::query()->create(['user_id' => $user->id, 'user_coupon_id' => $coupon->id, 'transaction_type' => 'use', 'status_before' => 'locked', 'status_after' => 'used', 'business_type' => 'order', 'business_id' => $order->public_id, 'event_key_hash' => hash('sha256', 'order-coupon-use:'.$order->public_id), 'reason' => '非现金订单创建成功后核销优惠券。']);
        }
        $order->update(['paid_points' => $order->payable_points, 'paid_at' => now()]);
    }

    private function coupon(User $user, ?string $couponId, int $cash, int $points): array
    {
        if (! $couponId) {
            return [null, 0, 0];
        }
        $coupon = UserCoupon::query()->where('public_id', $couponId)->where('user_id', $user->id)->firstOrFail();
        if ($coupon->status !== 'unused' || $coupon->valid_from->isFuture() || $coupon->valid_to->isPast()) {
            throw ValidationException::withMessages(['coupon_id' => '优惠券已不可用。']);
        }
        $snapshot = $coupon->template_snapshot;
        if ($cash < $snapshot['minimum_cash_cents'] || $points < $snapshot['minimum_points']) {
            throw ValidationException::withMessages(['coupon_id' => '订单未达到优惠券使用门槛。']);
        }

        return [$coupon, min($cash, (int) $snapshot['cash_discount_cents']), min($points, (int) $snapshot['points_discount'])];
    }

    private function shipping(UserAddress $address, Collection $items, int $cashSubtotal): array
    {
        $groups = [];
        $total = 0;
        foreach ($items->groupBy(fn ($item) => $item['sku']->shipping_template_id) as $templateId => $templateItems) {
            if (! $templateId) {
                throw ValidationException::withMessages(['shipping' => '物流 SKU 未配置运费模板。']);
            }
            $template = $templateItems->first()['sku']->shippingTemplate;
            if ($template->status !== 'active') {
                throw ValidationException::withMessages(['shipping' => '运费模板不可用。']);
            }
            $content = $template->versions->firstWhere('version_no', $template->current_version_no)->content;
            $region = collect($content['regions'])->first(fn ($rule) => count(array_intersect($rule['region_codes'], [$address->province, $address->city, $address->district])) > 0) ?? collect($content['regions'])->first(fn ($rule) => in_array('*', $rule['region_codes'], true));
            if (! $region || ! $region['deliverable']) {
                throw ValidationException::withMessages(['address_id' => '当前地址不在配送范围内。']);
            }
            $quantity = $templateItems->sum('quantity');
            $weight = $templateItems->sum(fn ($item) => (int) $item['sku']->weight_grams * $item['quantity']);
            $free = $templateItems->contains(fn ($item) => $item['sku']->free_shipping) || collect($content['free_rules'])->contains(fn ($rule) => $this->freeRule($rule, $templateItems, $cashSubtotal, $quantity));
            $base = $free ? 0 : $this->shippingCharge($content, $quantity, $weight);
            $surcharge = $free ? 0 : (int) $region['remote_surcharge_amount'];
            $amount = $base + $surcharge;
            $total += $amount;
            $groups[] = ['template_id' => $template->public_id, 'template_version' => $template->current_version_no, 'charge_type' => $content['charge_type'], 'quantity' => $quantity, 'weight_grams' => $weight, 'base_amount' => $base, 'remote_surcharge_amount' => $surcharge, 'free_shipping' => $free, 'amount' => $amount];
        }

        return ['amount' => $total, 'groups' => $groups];
    }

    private function shippingCharge(array $content, int $quantity, int $weight): int
    {
        if ($content['charge_type'] === 'fixed') {
            return (int) $content['fixed_amount'];
        }
        $units = $content['charge_type'] === 'by_quantity' ? $quantity : $weight / 1000;

        return (int) $content['first_amount'] + (int) ceil(max(0, $units - (float) $content['first_unit']) / (float) $content['additional_unit']) * (int) $content['additional_amount'];
    }

    private function freeRule(array $rule, Collection $items, int $cash, int $quantity): bool
    {
        return match ($rule['type']) {
            'cash_threshold' => $cash >= (int) ($rule['threshold_amount'] ?? PHP_INT_MAX), 'quantity_threshold' => $quantity >= (int) ($rule['threshold_quantity'] ?? PHP_INT_MAX),
            'sku' => $items->contains(fn ($item) => in_array($item['sku']->public_id, $rule['target_ids'] ?? [], true)), 'product' => $items->contains(fn ($item) => in_array($item['sku']->product->public_id, $rule['target_ids'] ?? [], true)), default => false,
        };
    }

    private function allocate(array $items, int $amount, string $basis): array
    {
        $total = collect($items)->sum($basis);
        $left = $amount;
        $result = [];
        foreach ($items as $index => $item) {
            $value = $index === array_key_last($items) ? $left : ($total > 0 ? intdiv($amount * $item[$basis], $total) : 0);
            $result[] = $value;
            $left -= $value;
        }

        return $result;
    }

    private function canonicalRequest(array $request): array
    {
        $normalized = [
            'capability_version' => (int) $request['capability_version'],
            'items' => array_map(static fn (array $item): array => [
                'sku_id' => trim((string) ($item['sku_id'] ?? '')),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'purchase_mode' => strtolower(trim((string) ($item['purchase_mode'] ?? ''))),
            ], array_values($request['items'])),
            'group_options' => array_map(static function (array $option): array {
                $normalized = [
                    'fulfillment_type' => strtolower(trim((string) ($option['fulfillment_type'] ?? ''))),
                    'address_id' => isset($option['address_id']) ? trim((string) $option['address_id']) : null,
                    'coupon_id' => isset($option['coupon_id']) ? trim((string) $option['coupon_id']) : null,
                    'cash_payment_method' => isset($option['cash_payment_method']) ? strtolower(trim((string) $option['cash_payment_method'])) : null,
                ];

                return array_filter($normalized, static fn ($value) => $value !== null && $value !== '');
            }, array_values($request['group_options'])),
        ];
        usort($normalized['items'], fn ($a, $b) => [$a['sku_id'], $a['purchase_mode']] <=> [$b['sku_id'], $b['purchase_mode']]);
        usort($normalized['group_options'], fn ($a, $b) => $a['fulfillment_type'] <=> $b['fulfillment_type']);
        foreach ($normalized['group_options'] as &$option) {
            ksort($option);
        } unset($option);

        return $normalized;
    }

    private function idempotencyKeyHash(int $actorId, string $operation, string $key): string
    {
        return hash('sha256', 'user:'.$actorId.':'.$operation.':'.trim($key));
    }

    private function requestFingerprint(array $request, string $platform): string
    {
        return $this->hash([
            'operation' => 'checkout.create',
            'platform' => strtolower(trim($platform)),
            'request' => $this->canonicalRequest($request),
        ]);
    }

    private function existingBatch(User $user, string $idempotencyHash, string $legacyIdempotencyHash): ?OrderBatch
    {
        $existing = OrderBatch::query()->where('idempotency_key_hash', $idempotencyHash)->lockForUpdate()->first();
        if ($existing !== null) {
            abort_unless($existing->user_id === $user->id, 404);

            return $existing;
        }

        // Batches written before the scoped key rollout used sha256(key). Only
        // replay a legacy row when it belongs to this actor; another actor may
        // safely use the same key under the new scoped hash.
        if ($legacyIdempotencyHash !== $idempotencyHash) {
            $legacy = OrderBatch::query()->where('idempotency_key_hash', $legacyIdempotencyHash)->lockForUpdate()->first();
            if ($legacy?->user_id === $user->id) {
                return $legacy;
            }
        }

        return null;
    }

    private function assertBatchFingerprint(OrderBatch $batch, string $fingerprint): void
    {
        $stored = $batch->request_fingerprint_hash;
        if (! is_string($stored) || $stored === '') {
            $snapshot = CheckoutPreview::query()->find($batch->checkout_preview_id)?->request_snapshot;
            if (is_array($snapshot)) {
                $stored = $this->requestFingerprint($snapshot, (string) $batch->client_platform);
            }
        }

        if (is_string($stored) && $stored !== '' && ! hash_equals($stored, $fingerprint)) {
            throw new IdempotencyConflictException('相同幂等键已绑定不同的订单请求，请更换幂等键。');
        }

        if ($batch->request_fingerprint_hash === null || $batch->request_fingerprint_hash === '') {
            $batch->update(['request_fingerprint_hash' => $fingerprint]);
        }
    }

    private function publicPricing(User $user, array $pricing): array
    {
        $coupons = UserCoupon::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['unused', 'locked'])
            ->orderBy('valid_to')
            ->orderBy('id')
            ->get();
        $groups = collect($pricing['groups'])->map(function (array $group) use ($coupons): array {
            return [
                ...collect($group)->except(['items', 'address', 'coupon'])->all(),
                'selected_coupon_id' => $group['coupon']?->public_id,
                'coupon_options' => $coupons->map(fn (UserCoupon $coupon): array => $this->couponOption(
                    $coupon,
                    (int) $group['items_cash_amount'],
                    (int) $group['items_points'],
                ))->values()->all(),
            ];
        })->all();

        return ['groups' => $groups, ...$this->totals($pricing)];
    }

    /** @return array<string,mixed> */
    private function couponOption(UserCoupon $coupon, int $cash, int $points): array
    {
        $snapshot = $coupon->template_snapshot;
        $minimumCash = (int) ($snapshot['minimum_cash_cents'] ?? 0);
        $minimumPoints = (int) ($snapshot['minimum_points'] ?? 0);
        $cashDiscount = min($cash, (int) ($snapshot['cash_discount_cents'] ?? 0));
        $pointsDiscount = min($points, (int) ($snapshot['points_discount'] ?? 0));
        $reason = null;

        if ($coupon->status === 'locked') {
            $reason = '已被其他订单占用';
        } elseif ($coupon->status !== 'unused') {
            $reason = '优惠券状态不可用';
        } elseif ($coupon->valid_from->isFuture()) {
            $reason = '尚未生效';
        } elseif ($coupon->valid_to->isPast()) {
            $reason = '已过期';
        } elseif ($cash < $minimumCash || $points < $minimumPoints) {
            $requirements = [];
            if ($cash < $minimumCash) {
                $requirements[] = '现金商品满 ¥'.number_format($minimumCash / 100, 2);
            }
            if ($points < $minimumPoints) {
                $requirements[] = '积分商品满 '.$minimumPoints.' 积分';
            }
            $reason = '未满足'.implode('且', $requirements);
        } elseif ($cashDiscount === 0 && $pointsDiscount === 0) {
            $reason = '该拆单没有可抵扣的现金或积分';
        }

        return [
            'id' => $coupon->public_id,
            'name' => (string) ($snapshot['name'] ?? '优惠券'),
            'cash_discount_amount' => (int) ($snapshot['cash_discount_cents'] ?? 0),
            'points_discount' => (int) ($snapshot['points_discount'] ?? 0),
            'minimum_cash_amount' => $minimumCash,
            'minimum_points' => $minimumPoints,
            'estimated_cash_discount' => $reason === null ? $cashDiscount : 0,
            'estimated_points_discount' => $reason === null ? $pointsDiscount : 0,
            'eligible' => $reason === null,
            'unavailable_reason' => $reason,
            'valid_from' => $coupon->valid_from?->toIso8601String(),
            'valid_to' => $coupon->valid_to?->toIso8601String(),
        ];
    }

    private function totals(array $pricing): array
    {
        return collect($pricing)->only(['items_cash_amount', 'items_points', 'cash_discount_amount', 'points_discount', 'shipping_amount', 'payable_cash_amount', 'payable_points'])->all();
    }

    private function groupTotals(array $group): array
    {
        return collect($group)->only(['items_cash_amount', 'items_points', 'cash_discount_amount', 'points_discount', 'shipping_amount', 'payable_cash_amount', 'payable_points'])->all();
    }

    private function sumGroups(Collection $groups): array
    {
        return ['items_cash_amount' => $groups->sum('items_cash_amount'), 'items_points' => $groups->sum('items_points'), 'cash_discount_amount' => $groups->sum('cash_discount_amount'), 'points_discount' => $groups->sum('points_discount'), 'shipping_amount' => $groups->sum('shipping_amount'), 'payable_cash_amount' => $groups->sum('payable_cash_amount'), 'payable_points' => $groups->sum('payable_points')];
    }

    private function addressSnapshot(UserAddress $address): array
    {
        return collect($address->only(['recipient_name', 'phone', 'province', 'city', 'district', 'detail', 'postal_code']))->all();
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function number(string $prefix): string
    {
        return $prefix.now()->format('YmdHis').strtoupper(Str::random(10));
    }
}
