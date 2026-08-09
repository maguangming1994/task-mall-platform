<?php

namespace App\Modules\Refund\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Order\Models\Order;
use App\Modules\Refund\Models\AfterSaleReason;
use App\Modules\Refund\Models\Refund;
use App\Modules\Refund\Models\ReturnShipment;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RefundService
{
    public function __construct(
        private readonly AssetLedgerService $assets,
        private readonly SystemSettingService $settings,
    ) {}

    public function create(User $user, Order $order, array $data, string $key): Refund
    {
        abort_unless($order->user_id === $user->id, 404);
        $hash = hash('sha256', $key);
        if ($refund = Refund::query()->where('idempotency_key_hash', $hash)->first()) {
            abort_unless($refund->user_id === $user->id, 404);

            return $refund;
        }

        return DB::transaction(function () use ($user, $order, $data, $hash) {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, ['pending_fulfillment', 'fulfilled', 'completed'], true)) {
                throw ValidationException::withMessages(['order' => '订单当前不可申请退款。']);
            }
            $serviceType = $data['service_type'] ?? 'refund_only';
            $reason = $this->reason($data['reason_code'], $serviceType);
            $evidence = $this->validateEvidence($user, $data['evidence_file_ids'] ?? [], $reason->evidence_required);
            if ($serviceType === 'return_and_refund' && $locked->shipped_at === null) {
                throw ValidationException::withMessages(['service_type' => '未发货订单应使用仅退款，无需填写退货物流。']);
            }
            $returnMethod = $serviceType === 'return_and_refund'
                ? ($data['return_method'] ?? ($locked->fulfillment_type === 'logistics' ? 'logistics' : 'no_logistics'))
                : null;
            if ($returnMethod === 'logistics' && $locked->fulfillment_type !== 'logistics') {
                throw ValidationException::withMessages(['return_method' => '无需物流或虚拟商品不能选择物流退回。']);
            }

            [$cash, $points, $rows] = $this->calculateItems($locked, $data['items']);
            if ($cash > $locked->paid_wallet_amount + $locked->paid_external_amount - $locked->refunded_wallet_amount - $locked->refunded_external_amount
                || $points > $locked->paid_points - $locked->refunded_points) {
                throw ValidationException::withMessages(['refund' => '累计退款超过实际支付金额或积分。']);
            }
            $wallet = $locked->paid_wallet_amount > 0 ? $cash : 0;
            $external = $locked->paid_external_amount > 0 ? $cash : 0;
            $snapshot = $this->reasonSnapshot($reason);
            $refund = Refund::query()->create([
                'refund_no' => 'R'.now()->format('YmdHis').strtoupper(Str::random(8)),
                'order_id' => $locked->id,
                'user_id' => $user->id,
                'service_type' => $serviceType,
                'reason_code' => $reason->code,
                'reason_name_snapshot' => $reason->name,
                'description' => $data['description'] ?? null,
                'status' => 'submitted',
                'refund_cash_amount' => $cash,
                'refund_wallet_amount' => $wallet,
                'refund_external_amount' => $external,
                'refund_points' => $points,
                'responsibility_party' => $reason->default_responsibility,
                'return_shipping_payer' => $reason->return_shipping_payer,
                'return_method' => $returnMethod,
                'reason_rule_snapshot' => $snapshot,
                'evidence_file_ids' => $evidence,
                'original_shipping_refunded' => false,
                'idempotency_key_hash' => $hash,
            ]);
            foreach ($rows as [$item, $quantity, $itemCash, $itemPoints, $remaining]) {
                DB::table('refund_items')->insert([
                    'refund_id' => $refund->id,
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'refund_cash_amount' => $itemCash,
                    'refund_points' => $itemPoints,
                    'remaining_refundable_snapshot' => $remaining,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $refund;
        });
    }

    public function approve(AdminUser $admin, Refund $refund, string $note, array $decision = []): Refund
    {
        return DB::transaction(function () use ($admin, $refund, $note, $decision) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if (in_array($locked->status, ['succeeded', 'processing', 'awaiting_return', 'return_in_transit'], true)) {
                return $locked;
            }
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['refund' => '退款状态不可审核。']);
            }
            $responsibility = $decision['responsibility_party'] ?? $locked->responsibility_party;
            $payer = $decision['return_shipping_payer'] ?? $locked->return_shipping_payer;
            $method = $decision['return_method'] ?? $locked->return_method;
            if (($responsibility !== $locked->responsibility_party || $payer !== $locked->return_shipping_payer) && trim($note) === '') {
                throw ValidationException::withMessages(['note' => '改变默认责任归属时必须填写审核理由。']);
            }
            $locked->update([
                'responsibility_party' => $responsibility,
                'return_shipping_payer' => $payer,
                'return_method' => $method,
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $note,
            ]);
            if ($locked->service_type === 'return_and_refund' && $method === 'logistics') {
                $days = (int) $this->settings->read('after_sales.user_action_days')['value'];
                $locked->update(['status' => 'awaiting_return', 'user_action_deadline_at' => now()->addDays($days)]);

                return $locked->fresh();
            }

            return $this->settle($admin, $locked);
        });
    }

    public function submitReturn(User $user, Refund $refund, array $data, string $key): Refund
    {
        abort_unless($refund->user_id === $user->id, 404);
        $hash = hash('sha256', $key);
        if (ReturnShipment::query()->where('idempotency_key_hash', $hash)->exists()) {
            return $refund->fresh();
        }

        return DB::transaction(function () use ($user, $refund, $data, $hash) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'awaiting_return' || $locked->user_action_deadline_at?->isPast()) {
                throw ValidationException::withMessages(['refund' => '当前不在可提交退货物流的期限内。']);
            }
            if ($locked->return_method !== 'logistics') {
                throw ValidationException::withMessages(['return_method' => '该售后无需物流。']);
            }
            $paid = (int) ($data['paid_shipping_amount'] ?? 0);
            $proof = null;
            if ($paid > 0) {
                if (empty($data['tracking_no']) || empty($data['payment_proof_file_id'])) {
                    throw ValidationException::withMessages(['payment_proof_file_id' => '申请退货运费补偿必须同时提供物流单号和付款凭证。']);
                }
                $proof = $this->validateProof($user, $data['payment_proof_file_id']);
            }
            $cap = (int) $this->settings->read('after_sales.return_shipping_compensation_cents')['value'];
            $compensation = in_array($locked->return_shipping_payer, ['seller', 'carrier', 'negotiated'], true)
                ? min($paid, $cap)
                : 0;
            ReturnShipment::query()->create([
                'refund_id' => $locked->id,
                'method' => 'logistics',
                'carrier_code' => $data['carrier_code'],
                'tracking_no' => $data['tracking_no'],
                'payment_proof_file_id' => $proof?->id,
                'paid_shipping_amount' => $paid,
                'compensation_amount' => $compensation,
                'responsibility_party' => $locked->responsibility_party,
                'status' => 'in_transit',
                'idempotency_key_hash' => $hash,
                'submitted_at' => now(),
            ]);
            $locked->update(['status' => 'return_in_transit']);

            return $locked->fresh();
        });
    }

    public function receiveReturn(AdminUser $admin, Refund $refund, string $note): Refund
    {
        return DB::transaction(function () use ($admin, $refund, $note) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($locked->status !== 'return_in_transit') {
                throw ValidationException::withMessages(['refund' => '当前没有待确认收货的退货。']);
            }
            ReturnShipment::query()->where('refund_id', $locked->id)->update(['status' => 'received', 'received_at' => now()]);
            $locked->update(['reviewed_by_admin_id' => $admin->id, 'review_note' => $note]);

            return $this->settle($admin, $locked);
        });
    }

    public function closeExpiredReturns(int $limit = 100): int
    {
        $count = 0;
        Refund::query()->where('status', 'awaiting_return')->where('user_action_deadline_at', '<=', now())
            ->limit($limit)->get()->each(function (Refund $refund) use (&$count): void {
                $count += Refund::query()->whereKey($refund->id)->where('status', 'awaiting_return')
                    ->update(['status' => 'closed', 'review_note' => '用户未在售后操作期限内提交退货物流。']);
            });

        return $count;
    }

    private function settle(AdminUser $admin, Refund $refund): Refund
    {
        $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
        $user = User::query()->findOrFail($refund->user_id);
        if ($refund->refund_external_amount > 0) {
            DB::table('refund_components')->updateOrInsert(
                ['refund_id' => $refund->id, 'component_type' => 'external'],
                ['amount' => $refund->refund_external_amount, 'status' => 'pending_provider', 'updated_at' => now(), 'created_at' => now()],
            );
            $refund->update(['status' => 'processing']);

            return $refund->fresh();
        }
        if ($refund->refund_wallet_amount > 0) {
            $this->assets->changeWallet($user, $refund->refund_wallet_amount, '订单退款退回余额。', 'refund-wallet:'.$refund->public_id, $admin, 'refund');
        }
        if ($refund->refund_points > 0) {
            $this->assets->grantPoints($user, $refund->refund_points, 'order_refund', $refund->public_id, '退款积分从到账日重新计算有效期。', 'refund-points:'.$refund->public_id);
        }
        foreach (DB::table('refund_items')->where('refund_id', $refund->id)->get() as $row) {
            DB::table('order_items')->where('id', $row->order_item_id)->update([
                'refunded_quantity' => DB::raw('refunded_quantity + '.$row->quantity),
                'refunded_cash_amount' => DB::raw('refunded_cash_amount + '.$row->refund_cash_amount),
                'refunded_points' => DB::raw('refunded_points + '.$row->refund_points),
            ]);
        }
        $newWallet = $order->refunded_wallet_amount + $refund->refund_wallet_amount;
        $newPoints = $order->refunded_points + $refund->refund_points;
        $fullyRefunded = $newWallet + $order->refunded_external_amount >= $order->paid_wallet_amount + $order->paid_external_amount
            && $newPoints >= $order->paid_points;
        $order->update([
            'refunded_wallet_amount' => $newWallet,
            'refunded_points' => $newPoints,
            'status' => $fullyRefunded ? 'refunded' : 'partially_refunded',
        ]);
        $refund->update(['status' => 'succeeded', 'completed_at' => now()]);

        return $refund->fresh();
    }

    private function reason(string $code, string $serviceType): AfterSaleReason
    {
        $reason = AfterSaleReason::query()->where('code', $code)->where('enabled', true)->first();
        if (! $reason || ! in_array($serviceType, $reason->service_types, true)) {
            throw ValidationException::withMessages(['reason_code' => '售后原因不存在、已停用或不适用于所选服务。']);
        }

        return $reason;
    }

    private function validateEvidence(User $user, array $ids, bool $required): array
    {
        $ids = array_values(array_unique($ids));
        if ($required && $ids === []) {
            throw ValidationException::withMessages(['evidence_file_ids' => '该售后原因必须上传有效凭证。']);
        }
        $files = UploadedFile::query()->whereIn('public_id', $ids)->get();
        if ($files->count() !== count($ids) || $files->contains(fn (UploadedFile $file) => $file->owner_user_id !== $user->id || $file->purpose !== 'refund_evidence' || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['evidence_file_ids' => '售后凭证不存在、未通过安全扫描或不属于当前用户。']);
        }

        return $ids;
    }

    private function validateProof(User $user, string $publicId): UploadedFile
    {
        $file = UploadedFile::query()->where('public_id', $publicId)->first();
        if (! $file || $file->owner_user_id !== $user->id || $file->purpose !== 'return_shipping' || $file->status !== 'clean') {
            throw ValidationException::withMessages(['payment_proof_file_id' => '付款凭证不存在、未通过安全扫描或不属于当前用户。']);
        }

        return $file;
    }

    private function reasonSnapshot(AfterSaleReason $reason): array
    {
        return [
            'version' => $reason->version,
            'default_responsibility' => $reason->default_responsibility,
            'return_shipping_payer' => $reason->return_shipping_payer,
            'evidence_required' => $reason->evidence_required,
        ];
    }

    private function calculateItems(Order $order, array $items): array
    {
        $cash = 0;
        $points = 0;
        $rows = [];
        foreach ($items as $row) {
            $item = $order->items->firstWhere('public_id', $row['order_item_id']);
            if (! $item) {
                throw ValidationException::withMessages(['items' => '订单项不存在。']);
            }
            $pending = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                ->where('order_item_id', $item->id)
                ->whereIn('refunds.status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing', 'succeeded'])
                ->sum('quantity');
            $remaining = $item->quantity - $item->refunded_quantity - $pending;
            if ($row['quantity'] > $remaining) {
                throw ValidationException::withMessages(['quantity' => '退货/退款数量超过剩余可退数量。']);
            }
            $itemCash = intdiv($item->paid_cash_amount * $row['quantity'], $item->quantity);
            $itemPoints = intdiv($item->paid_points * $row['quantity'], $item->quantity);
            $cash += $itemCash;
            $points += $itemPoints;
            $rows[] = [$item, $row['quantity'], $itemCash, $itemPoints, $remaining];
        }

        return [$cash, $points, $rows];
    }
}
