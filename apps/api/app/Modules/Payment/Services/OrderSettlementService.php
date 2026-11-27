<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\CouponTransaction;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Models\WalletTransaction;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Order\Models\Order;
use App\Modules\Store\Models\StoreInventory;
use App\Modules\Store\Models\StoreInventoryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSettlementService
{
    public function settleWallet(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending_payment' || $locked->cash_payment_method !== 'wallet') {
                throw ValidationException::withMessages(['order' => '订单当前不能使用余额支付。']);
            }
            if ($locked->expires_at?->isPast()) {
                throw ValidationException::withMessages(['order' => '订单已超过支付期限。']);
            }
            $account = UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($account->wallet_available_cents < $locked->payable_cash_amount) {
                throw ValidationException::withMessages(['wallet' => '余额不足，订单不会部分扣款。']);
            }
            $account->update(['wallet_available_cents' => $account->wallet_available_cents - $locked->payable_cash_amount, 'version' => $account->version + 1]);
            WalletTransaction::query()->create(['user_id' => $user->id, 'transaction_type' => 'order_payment', 'change_available_cents' => -$locked->payable_cash_amount, 'change_frozen_cents' => 0, 'available_after_cents' => $account->wallet_available_cents, 'frozen_after_cents' => $account->wallet_frozen_cents, 'business_type' => 'order', 'business_id' => $locked->public_id, 'event_key_hash' => hash('sha256', 'wallet-order-payment:'.$locked->public_id), 'reason' => '余额全额支付订单。']);
            $this->consumeReservedResources($locked, $user);
            $locked->update(['status' => 'pending_fulfillment', 'paid_wallet_amount' => $locked->payable_cash_amount, 'paid_points' => $locked->payable_points, 'paid_at' => now()]);
            DB::table('order_status_changes')->insert(['order_id' => $locked->id, 'from_status' => 'pending_payment', 'to_status' => 'pending_fulfillment', 'event' => 'wallet_payment_succeeded', 'reason' => '余额全额扣款并完成资源结算。', 'created_at' => now(), 'updated_at' => now()]);

            return $locked->fresh('items');
        });
    }

    public function settleExternal(Order $order, User $user, int $cashAmount): Order
    {
        return DB::transaction(function () use ($order, $user, $cashAmount) {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'pending_fulfillment' && $locked->paid_external_amount === $cashAmount) {
                return $locked;
            }
            if ($locked->status !== 'pending_payment' || $locked->cash_payment_method !== 'wechat' || $locked->payable_cash_amount !== $cashAmount) {
                throw ValidationException::withMessages(['payment' => '支付金额或订单状态不匹配。']);
            }
            $this->consumeReservedResources($locked, $user);
            $locked->update(['status' => 'pending_fulfillment', 'paid_external_amount' => $cashAmount, 'paid_points' => $locked->payable_points, 'paid_at' => now()]);
            DB::table('order_status_changes')->insert(['order_id' => $locked->id, 'from_status' => 'pending_payment', 'to_status' => 'pending_fulfillment', 'event' => 'wechat_payment_succeeded', 'reason' => '可信微信支付结果完成资源结算。', 'created_at' => now(), 'updated_at' => now()]);

            return $locked->fresh('items');
        });
    }

    private function consumeReservedResources(Order $order, User $user): void
    {
        foreach ($order->items as $item) {
            if ($item->store_sku_id !== null) {
                $this->consumeStoreReservation($item);
                $item->update(['paid_cash_amount' => $item->cash_subtotal - $item->cash_discount_allocated, 'paid_points' => 0]);

                continue;
            }
            $reservation = DB::table('inventory_reservations')->where('order_item_id', $item->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved') {
                throw ValidationException::withMessages(['inventory' => '订单库存预占状态异常。']);
            }
            $inventory = Inventory::query()->lockForUpdate()->findOrFail($reservation->inventory_id);
            $beforeOnHand = $inventory->on_hand_quantity;
            $beforeReserved = $inventory->reserved_quantity;
            $inventory->update(['on_hand_quantity' => $beforeOnHand - $reservation->quantity, 'reserved_quantity' => $beforeReserved - $reservation->quantity, 'version' => $inventory->version + 1]);
            DB::table('inventory_reservations')->where('id', $reservation->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
            InventoryTransaction::query()->create(['inventory_id' => $inventory->id, 'transaction_type' => 'sale_consume', 'on_hand_change' => -$reservation->quantity, 'reserved_change' => -$reservation->quantity, 'on_hand_before' => $beforeOnHand, 'on_hand_after' => $inventory->on_hand_quantity, 'reserved_before' => $beforeReserved, 'reserved_after' => $inventory->reserved_quantity, 'business_type' => 'order_item', 'business_id' => $item->public_id, 'reason' => '支付成功后扣减预占库存。', 'idempotency_key_hash' => hash('sha256', 'paid-order-consume:'.$item->public_id)]);
            $item->update(['paid_cash_amount' => $item->cash_subtotal - $item->cash_discount_allocated, 'paid_points' => $item->points_subtotal - $item->points_discount_allocated]);
        }
        $reservation = DB::table('order_point_reservations')->where('order_id', $order->id)->lockForUpdate()->first();
        if ($reservation && $reservation->status === 'frozen') {
            $account = UserAssetAccount::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            foreach (DB::table('order_point_reservation_allocations')->where('order_point_reservation_id', $reservation->id)->get() as $allocation) {
                $batch = PointBatch::query()->lockForUpdate()->findOrFail($allocation->point_batch_id);
                $batch->update(['frozen_points' => $batch->frozen_points - $allocation->points, 'status' => $batch->remaining_points === 0 && $batch->frozen_points - $allocation->points === 0 ? 'exhausted' : 'available']);
            }
            $account->update(['points_frozen' => $account->points_frozen - $reservation->points, 'version' => $account->version + 1]);
            PointTransaction::query()->create(['user_id' => $user->id, 'transaction_type' => 'order_consume', 'change_available' => 0, 'change_frozen' => -$reservation->points, 'available_after' => $account->points_available, 'frozen_after' => $account->points_frozen, 'debt_after' => $account->points_debt, 'source_code' => 'order', 'source_business_id' => $order->public_id, 'event_key_hash' => hash('sha256', 'paid-order-points:'.$order->public_id), 'reason' => '支付成功后扣除冻结积分。']);
            DB::table('order_point_reservations')->where('id', $reservation->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
        }
        if ($order->user_coupon_id) {
            $coupon = UserCoupon::query()->lockForUpdate()->findOrFail($order->user_coupon_id);
            if ($coupon->status === 'locked') {
                $coupon->update(['status' => 'used', 'used_at' => now()]);
                CouponTransaction::query()->create(['user_id' => $user->id, 'user_coupon_id' => $coupon->id, 'transaction_type' => 'use', 'status_before' => 'locked', 'status_after' => 'used', 'business_type' => 'order', 'business_id' => $order->public_id, 'event_key_hash' => hash('sha256', 'paid-order-coupon:'.$order->public_id), 'reason' => '支付成功后核销优惠券。']);
            }
        }
    }

    private function consumeStoreReservation($item): void
    {
        $reservation = DB::table('store_inventory_reservations')->where('order_item_id', $item->id)->lockForUpdate()->first();
        if (! $reservation || $reservation->status !== 'reserved') {
            throw ValidationException::withMessages(['inventory' => '门店库存预占状态异常。']);
        }
        $inventory = StoreInventory::query()->lockForUpdate()->findOrFail($reservation->store_inventory_id);
        $beforeOnHand = (int) $inventory->on_hand_quantity;
        $beforeReserved = (int) $inventory->reserved_quantity;
        $inventory->update([
            'on_hand_quantity' => $beforeOnHand - $reservation->quantity,
            'reserved_quantity' => $beforeReserved - $reservation->quantity,
            'version' => $inventory->version + 1,
        ]);
        DB::table('store_inventory_reservations')->where('id', $reservation->id)->update(['status' => 'consumed', 'consumed_at' => now(), 'updated_at' => now()]);
        StoreInventoryTransaction::query()->create([
            'store_inventory_id' => $inventory->id, 'transaction_type' => 'sale_consume',
            'on_hand_change' => -$reservation->quantity, 'reserved_change' => -$reservation->quantity,
            'on_hand_before' => $beforeOnHand, 'on_hand_after' => $inventory->on_hand_quantity,
            'reserved_before' => $beforeReserved, 'reserved_after' => $inventory->reserved_quantity,
            'business_type' => 'order_item', 'business_id' => $item->public_id,
            'reason' => '支付成功后扣减门店预占库存。',
            'idempotency_key_hash' => hash('sha256', 'store-paid-order-consume:'.$item->public_id),
        ]);
    }
}
