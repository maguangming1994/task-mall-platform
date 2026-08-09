<?php

namespace App\Modules\Fulfillment\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Fulfillment\Models\Fulfillment;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Order\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FulfillmentService
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function create(AdminUser $admin, Order $order, array $data, string $key): Fulfillment
    {
        $hash = hash('sha256', $key);
        if ($found = Fulfillment::query()->where('idempotency_key_hash', $hash)->first()) {
            return $found;
        }

        return DB::transaction(function () use ($admin, $order, $data, $hash) {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, ['pending_fulfillment', 'fulfilled'], true)) {
                throw ValidationException::withMessages(['order' => '订单当前不可履约。']);
            }
            if ($data['method'] === 'logistics' && (empty($data['carrier_code']) || empty($data['tracking_no']))) {
                throw ValidationException::withMessages(['tracking_no' => '物流发货必须填写承运商和物流单号。']);
            }
            if ($data['method'] !== 'logistics' && empty($data['no_logistics_reason'])) {
                throw ValidationException::withMessages(['no_logistics_reason' => '无需物流或虚拟交付必须填写说明。']);
            }
            $f = Fulfillment::query()->create(['fulfillment_no' => 'F'.now()->format('YmdHis').strtoupper(Str::random(8)), 'order_id' => $locked->id, 'method' => $data['method'], 'status' => $data['method'] === 'logistics' ? 'shipped' : 'delivered', 'carrier_code' => $data['carrier_code'] ?? null, 'carrier_name' => $data['carrier_name'] ?? null, 'tracking_no' => $data['tracking_no'] ?? null, 'no_logistics_reason' => $data['no_logistics_reason'] ?? null, 'virtual_delivery_snapshot' => $data['virtual_delivery'] ?? null, 'idempotency_key_hash' => $hash, 'created_by_admin_id' => $admin->id, 'fulfilled_at' => now()]);
            foreach ($data['items'] as $row) {
                $item = $locked->items->firstWhere('public_id', $row['order_item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['items' => '订单项不存在。']);
                } $done = DB::table('fulfillment_items')->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')->where('fulfillments.order_id', $locked->id)->where('order_item_id', $item->id)->sum('quantity');
                if ($done + $row['quantity'] > $item->quantity) {
                    throw ValidationException::withMessages(['items' => '履约数量超过剩余数量。']);
                } DB::table('fulfillment_items')->insert(['fulfillment_id' => $f->id, 'order_item_id' => $item->id, 'quantity' => $row['quantity'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $totalDone = DB::table('fulfillment_items')->join('fulfillments', 'fulfillments.id', '=', 'fulfillment_items.fulfillment_id')->where('fulfillments.order_id', $locked->id)->sum('quantity');
            $total = $locked->items->sum('quantity');
            if ($totalDone === $total) {
                $locked->update(['status' => 'fulfilled', 'shipped_at' => $locked->shipped_at ?? now(), 'auto_complete_at' => now()->addDays(7)]);
                DB::table('order_status_changes')->insert(['order_id' => $locked->id, 'from_status' => 'pending_fulfillment', 'to_status' => 'fulfilled', 'event' => 'fulfillment_completed', 'reason' => '全部订单项已完成发货或交付。', 'created_at' => now(), 'updated_at' => now()]);
            }

            return $f;
        });
    }

    public function complete(User $user, Order $order): Order
    {
        abort_unless($order->user_id === $user->id, 404);

        return DB::transaction(function () use ($order, $user) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === 'completed') {
                return $locked;
            } if ($locked->status !== 'fulfilled') {
                throw ValidationException::withMessages(['order' => '订单尚未全部履约。']);
            } $locked->update(['status' => 'completed', 'completed_at' => now()]);
            DB::table('order_status_changes')->insert(['order_id' => $locked->id, 'from_status' => 'fulfilled', 'to_status' => 'completed', 'event' => 'user_confirmed', 'reason' => '用户确认完成。', 'created_at' => now(), 'updated_at' => now()]);
            $this->referrals->recordFirstCashOrder($user, $locked->public_id, $locked->paid_wallet_amount + $locked->paid_external_amount, 'completed');

            return $locked->fresh();
        });
    }
}
