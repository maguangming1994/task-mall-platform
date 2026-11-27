<?php

namespace App\Modules\Payment\Services;

use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Assets\Models\CouponTransaction;
use App\Modules\Assets\Models\PointBatch;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Order\Models\Order;
use App\Modules\Store\Models\StoreInventory;
use App\Modules\Store\Models\StoreInventoryTransaction;
use Illuminate\Support\Facades\DB;

class OrderPaymentReleaseService
{
    public function close(Order $order, string $event, string $reason): Order
    {
        return DB::transaction(function () use ($order, $event, $reason) {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if ($locked->status !== 'pending_payment') {
                return $locked;
            }
            foreach ($locked->items as $item) {
                if ($item->store_sku_id !== null) {
                    $this->releaseStoreReservation($item, $reason);

                    continue;
                }
                $reservation = DB::table('inventory_reservations')->where('order_item_id', $item->id)->lockForUpdate()->first();
                if (! $reservation || $reservation->status !== 'reserved') {
                    continue;
                }
                $inventory = Inventory::query()->lockForUpdate()->findOrFail($reservation->inventory_id);
                $before = $inventory->reserved_quantity;
                $inventory->update(['reserved_quantity' => $before - $reservation->quantity, 'version' => $inventory->version + 1]);
                DB::table('inventory_reservations')->where('id', $reservation->id)->update(['status' => 'released', 'released_at' => now(), 'updated_at' => now()]);
                InventoryTransaction::query()->create(['inventory_id' => $inventory->id, 'transaction_type' => 'release', 'on_hand_change' => 0, 'reserved_change' => -$reservation->quantity, 'on_hand_before' => $inventory->on_hand_quantity, 'on_hand_after' => $inventory->on_hand_quantity, 'reserved_before' => $before, 'reserved_after' => $inventory->reserved_quantity, 'business_type' => 'order_item', 'business_id' => $item->public_id, 'reason' => $reason, 'idempotency_key_hash' => hash('sha256', 'order-release:'.$item->public_id)]);
            }
            $pointReservation = DB::table('order_point_reservations')->where('order_id', $locked->id)->lockForUpdate()->first();
            if ($pointReservation && $pointReservation->status === 'frozen') {
                $account = UserAssetAccount::query()->where('user_id', $locked->user_id)->lockForUpdate()->firstOrFail();
                foreach (DB::table('order_point_reservation_allocations')->where('order_point_reservation_id', $pointReservation->id)->get() as $allocation) {
                    $batch = PointBatch::query()->lockForUpdate()->findOrFail($allocation->point_batch_id);
                    $batch->update(['remaining_points' => $batch->remaining_points + $allocation->points, 'frozen_points' => $batch->frozen_points - $allocation->points, 'status' => 'available']);
                }
                $account->update(['points_available' => $account->points_available + $pointReservation->points, 'points_frozen' => $account->points_frozen - $pointReservation->points, 'version' => $account->version + 1]);
                PointTransaction::query()->create(['user_id' => $locked->user_id, 'transaction_type' => 'order_release', 'change_available' => $pointReservation->points, 'change_frozen' => -$pointReservation->points, 'available_after' => $account->points_available, 'frozen_after' => $account->points_frozen, 'debt_after' => $account->points_debt, 'source_code' => 'order', 'source_business_id' => $locked->public_id, 'event_key_hash' => hash('sha256', 'order-points-release:'.$locked->public_id), 'reason' => $reason]);
                DB::table('order_point_reservations')->where('id', $pointReservation->id)->update(['status' => 'released', 'released_at' => now(), 'updated_at' => now()]);
            }
            if ($locked->user_coupon_id) {
                $coupon = UserCoupon::query()->lockForUpdate()->findOrFail($locked->user_coupon_id);
                if ($coupon->status === 'locked') {
                    $coupon->update(['status' => 'unused']);
                    $account = UserAssetAccount::query()->where('user_id', $locked->user_id)->lockForUpdate()->firstOrFail();
                    $account->update(['coupon_available' => $account->coupon_available + 1, 'version' => $account->version + 1]);
                    CouponTransaction::query()->create(['user_id' => $locked->user_id, 'user_coupon_id' => $coupon->id, 'transaction_type' => 'unlock', 'status_before' => 'locked', 'status_after' => 'unused', 'business_type' => 'order', 'business_id' => $locked->public_id, 'event_key_hash' => hash('sha256', 'order-coupon-release:'.$locked->public_id), 'reason' => $reason]);
                }
            }
            $locked->update(['status' => 'cancelled']);
            DB::table('order_status_changes')->insert(['order_id' => $locked->id, 'from_status' => 'pending_payment', 'to_status' => 'cancelled', 'event' => $event, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);

            return $locked->fresh('items');
        });
    }

    private function releaseStoreReservation($item, string $reason): void
    {
        $reservation = DB::table('store_inventory_reservations')->where('order_item_id', $item->id)->lockForUpdate()->first();
        if (! $reservation || $reservation->status !== 'reserved') {
            return;
        }
        $inventory = StoreInventory::query()->lockForUpdate()->findOrFail($reservation->store_inventory_id);
        $before = (int) $inventory->reserved_quantity;
        $inventory->update(['reserved_quantity' => $before - $reservation->quantity, 'version' => $inventory->version + 1]);
        DB::table('store_inventory_reservations')->where('id', $reservation->id)->update(['status' => 'released', 'released_at' => now(), 'updated_at' => now()]);
        StoreInventoryTransaction::query()->create([
            'store_inventory_id' => $inventory->id, 'transaction_type' => 'release',
            'on_hand_change' => 0, 'reserved_change' => -$reservation->quantity,
            'on_hand_before' => $inventory->on_hand_quantity, 'on_hand_after' => $inventory->on_hand_quantity,
            'reserved_before' => $before, 'reserved_after' => $inventory->reserved_quantity,
            'business_type' => 'order_item', 'business_id' => $item->public_id,
            'reason' => $reason,
            'idempotency_key_hash' => hash('sha256', 'store-order-release:'.$item->public_id),
        ]);
    }
}
