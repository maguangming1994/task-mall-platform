<?php

namespace App\Modules\Refund\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Refund\Models\AfterSaleReason;
use App\Modules\Refund\Models\Exchange;
use App\Modules\Refund\Models\ExchangeShipment;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExchangeService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function create(User $user, array $data, string $key): Exchange
    {
        $hash = hash('sha256', $key);
        if ($exchange = Exchange::query()->where('idempotency_key_hash', $hash)->first()) {
            abort_unless($exchange->user_id === $user->id, 404);

            return $exchange;
        }

        return DB::transaction(function () use ($user, $data, $hash) {
            $item = OrderItem::query()->lockForUpdate()->where('public_id', $data['order_item_id'])->firstOrFail();
            $order = DB::table('orders')->where('id', $item->order_id)->first();
            abort_unless($order && $order->user_id === $user->id, 404);
            if (! in_array($order->status, ['fulfilled', 'completed'], true)) {
                throw ValidationException::withMessages(['order_item_id' => '订单尚未交付，不能申请换货。']);
            }
            $reason = AfterSaleReason::query()->where('code', $data['reason_code'])->where('enabled', true)->first();
            if (! $reason || ! in_array('exchange', $reason->service_types, true)) {
                throw ValidationException::withMessages(['reason_code' => '换货原因不可用。']);
            }
            $ids = array_values(array_unique($data['evidence_file_ids'] ?? []));
            $this->validateFiles($user, $ids, $reason->evidence_required, 'refund_evidence');
            if ($data['return_method'] === 'logistics' && $item->fulfillment_type !== 'logistics') {
                throw ValidationException::withMessages(['return_method' => '无需物流或虚拟商品不能选择物流寄回。']);
            }
            $activeExchanges = Exchange::query()->where('order_item_id', $item->id)
                ->whereNotIn('status', ['rejected', 'closed', 'cancelled'])->sum('quantity');
            $refunds = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                ->where('order_item_id', $item->id)
                ->whereIn('refunds.status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing', 'succeeded'])
                ->sum('quantity');
            if ($data['quantity'] > $item->quantity - $activeExchanges - $refunds) {
                throw ValidationException::withMessages(['quantity' => '换货数量超过剩余可换数量。']);
            }
            $snapshot = ['version' => $reason->version, 'default_responsibility' => $reason->default_responsibility, 'return_shipping_payer' => $reason->return_shipping_payer, 'evidence_required' => $reason->evidence_required];

            return Exchange::query()->create([
                'exchange_no' => 'E'.now()->format('YmdHis').strtoupper(Str::random(8)),
                'user_id' => $user->id,
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'quantity' => $data['quantity'],
                'reason_code' => $reason->code,
                'reason_name_snapshot' => $reason->name,
                'reason_rule_snapshot' => $snapshot,
                'description' => $data['description'] ?? null,
                'evidence_file_ids' => $ids,
                'return_method' => $data['return_method'],
                'status' => 'submitted',
                'responsibility_party' => $reason->default_responsibility,
                'return_shipping_payer' => $reason->return_shipping_payer,
                'replacement_shipping_payer' => $reason->return_shipping_payer,
                'idempotency_key_hash' => $hash,
            ]);
        });
    }

    public function review(AdminUser $admin, Exchange $exchange, array $data): Exchange
    {
        return DB::transaction(function () use ($admin, $exchange, $data) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['exchange' => '换货申请状态不可审核。']);
            }
            if ($data['decision'] === 'reject') {
                $locked->update(['status' => 'rejected', 'reviewed_by_admin_id' => $admin->id, 'review_note' => $data['reason']]);

                return $locked->fresh();
            }
            $method = $data['return_method'] ?? $locked->return_method;
            $responsibility = $data['responsibility_party'] ?? $locked->responsibility_party;
            $payer = $data['return_shipping_payer'] ?? $locked->return_shipping_payer;
            $replacementPayer = $data['replacement_shipping_payer'] ?? $payer;
            $updates = [
                'return_method' => $method,
                'responsibility_party' => $responsibility,
                'return_shipping_payer' => $payer,
                'replacement_shipping_payer' => $replacementPayer,
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $data['reason'],
            ];
            if ($method === 'logistics') {
                $days = (int) $this->settings->read('after_sales.user_action_days')['value'];
                $updates += ['status' => 'waiting_return', 'return_deadline_at' => now()->addDays($days)];
            } else {
                $updates['status'] = 'replacement_pending';
            }
            $locked->update($updates);

            return $locked->fresh();
        });
    }

    public function submitReturn(User $user, Exchange $exchange, array $data, string $key): Exchange
    {
        abort_unless($exchange->user_id === $user->id, 404);
        $hash = hash('sha256', $key);
        if (ExchangeShipment::query()->where('idempotency_key_hash', $hash)->exists()) {
            return $exchange->fresh();
        }

        return DB::transaction(function () use ($user, $exchange, $data, $hash) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            if ($locked->status !== 'waiting_return' || $locked->return_deadline_at?->isPast()) {
                throw ValidationException::withMessages(['exchange' => '当前不在可提交寄回物流的期限内。']);
            }
            $paid = (int) ($data['paid_shipping_amount'] ?? 0);
            $proof = null;
            if ($paid > 0) {
                if (empty($data['payment_proof_file_id'])) {
                    throw ValidationException::withMessages(['payment_proof_file_id' => '申请运费补偿必须上传付款凭证。']);
                }
                $proof = $this->validateFiles($user, [$data['payment_proof_file_id']], true, 'return_shipping')->first();
            }
            $cap = (int) $this->settings->read('after_sales.return_shipping_compensation_cents')['value'];
            $compensation = in_array($locked->return_shipping_payer, ['seller', 'carrier', 'negotiated'], true) ? min($paid, $cap) : 0;
            ExchangeShipment::query()->create([
                'exchange_id' => $locked->id,
                'direction' => 'return',
                'method' => 'logistics',
                'carrier_code' => $data['carrier_code'],
                'tracking_no' => $data['tracking_no'],
                'payment_proof_file_id' => $proof?->id,
                'paid_shipping_amount' => $paid,
                'compensation_amount' => $compensation,
                'status' => 'in_transit',
                'idempotency_key_hash' => $hash,
                'shipped_at' => now(),
            ]);
            $locked->update(['status' => 'return_in_transit']);

            return $locked->fresh();
        });
    }

    public function fulfillReplacement(AdminUser $admin, Exchange $exchange, array $data, string $key): Exchange
    {
        $hash = hash('sha256', $key);
        if (ExchangeShipment::query()->where('idempotency_key_hash', $hash)->exists()) {
            return $exchange->fresh();
        }

        return DB::transaction(function () use ($exchange, $data, $hash) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            if (! in_array($locked->status, ['return_in_transit', 'replacement_pending'], true)) {
                throw ValidationException::withMessages(['exchange' => '当前不可发出换货商品。']);
            }
            if ($data['fulfillment_type'] === 'logistics' && (empty($data['carrier_code']) || empty($data['tracking_no']))) {
                throw ValidationException::withMessages(['tracking_no' => '物流发货必须填写承运商和物流单号。']);
            }
            if ($data['fulfillment_type'] === 'no_logistics' && empty($data['no_logistics_note'])) {
                throw ValidationException::withMessages(['no_logistics_note' => '无需物流必须填写交付说明。']);
            }
            ExchangeShipment::query()->create([
                'exchange_id' => $locked->id,
                'direction' => 'replacement',
                'method' => $data['fulfillment_type'],
                'carrier_code' => $data['carrier_code'] ?? null,
                'tracking_no' => $data['tracking_no'] ?? null,
                'no_logistics_note' => $data['no_logistics_note'] ?? null,
                'status' => $data['fulfillment_type'] === 'logistics' ? 'shipped' : 'delivered',
                'idempotency_key_hash' => $hash,
                'shipped_at' => now(),
            ]);
            $locked->update(['status' => $data['fulfillment_type'] === 'logistics' ? 'replacement_shipped' : 'completed', 'completed_at' => $data['fulfillment_type'] === 'logistics' ? null : now()]);

            return $locked->fresh();
        });
    }

    public function closeExpired(int $limit = 100): int
    {
        return Exchange::query()->where('status', 'waiting_return')->where('return_deadline_at', '<=', now())->limit($limit)
            ->update(['status' => 'closed', 'review_note' => '用户未在售后操作期限内提交寄回物流。']);
    }

    private function validateFiles(User $user, array $ids, bool $required, string $purpose)
    {
        if ($required && $ids === []) {
            throw ValidationException::withMessages(['evidence_file_ids' => '该原因必须上传有效凭证。']);
        }
        $files = UploadedFile::query()->whereIn('public_id', $ids)->get();
        if ($files->count() !== count($ids) || $files->contains(fn (UploadedFile $file) => $file->owner_user_id !== $user->id || $file->purpose !== $purpose || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['evidence_file_ids' => '凭证不存在、未通过安全扫描或不属于当前用户。']);
        }

        return $files;
    }
}
