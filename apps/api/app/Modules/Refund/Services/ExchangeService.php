<?php

namespace App\Modules\Refund\Services;

use App\Common\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Refund\Models\AfterSaleReason;
use App\Modules\Refund\Models\Exchange;
use App\Modules\Refund\Models\ExchangeShipment;
use App\Modules\Refund\Models\ReturnShippingCompensation;
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
        $hash = $this->idempotencyKeyHash($user->id, 'exchange.create', $key);
        $legacyHash = hash('sha256', $key);
        $fingerprint = $this->createFingerprint($data);

        return DB::transaction(function () use ($user, $data, $hash, $legacyHash, $fingerprint) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = $this->existingExchange($user, $hash, $legacyHash);
            if ($existing !== null) {
                $this->assertExchangeFingerprint($existing, $fingerprint);

                return $existing->fresh();
            }
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
                'request_fingerprint_hash' => $fingerprint,
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
        $hash = $this->idempotencyKeyHash($user->id, 'exchange.return-shipment:'.$exchange->id, $key);
        $legacyHash = hash('sha256', $key);
        $fingerprint = $this->returnShipmentFingerprint($exchange, $data);

        return DB::transaction(function () use ($user, $exchange, $data, $hash, $legacyHash, $fingerprint) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            $existing = $this->existingShipment($locked, $hash, $legacyHash, 'return');
            if ($existing !== null) {
                $this->assertShipmentFingerprint($existing, $fingerprint, $locked, 'return');

                return $locked->fresh();
            }
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
            $shipment = ExchangeShipment::query()->create([
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
                'request_fingerprint_hash' => $fingerprint,
                'shipped_at' => now(),
            ]);
            if ($compensation > 0) {
                ReturnShippingCompensation::query()->create([
                    'user_id' => $user->id,
                    'source_type' => 'exchange_return',
                    'source_id' => $shipment->id,
                    'requested_amount' => $compensation,
                    'status' => 'submitted',
                    'carrier_code' => $data['carrier_code'],
                    'tracking_no' => $data['tracking_no'],
                    'payment_proof_file_id' => $proof->id,
                ]);
            }
            $locked->update(['status' => 'return_in_transit']);

            return $locked->fresh();
        });
    }

    public function receiveReturn(Exchange $exchange, string $note): Exchange
    {
        return DB::transaction(function () use ($exchange, $note) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            if ($locked->status !== 'return_in_transit') {
                throw ValidationException::withMessages(['exchange' => '当前没有待验收的换货寄回商品。']);
            }

            $shipment = ExchangeShipment::query()
                ->where('exchange_id', $locked->id)
                ->where('direction', 'return')
                ->where('status', 'in_transit')
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if (! $shipment) {
                throw ValidationException::withMessages(['exchange' => '未找到待验收的寄回物流记录。']);
            }

            $shipment->update(['status' => 'received', 'received_at' => now()]);
            $reviewNote = trim(implode("\n", array_filter([$locked->review_note, '仓库验收：'.$note])));
            $locked->update(['status' => 'replacement_pending', 'review_note' => $reviewNote]);

            return $locked->fresh();
        });
    }

    public function fulfillReplacement(AdminUser $admin, Exchange $exchange, array $data, string $key): Exchange
    {
        $hash = $this->idempotencyKeyHash($admin->id, 'exchange.replacement-fulfillment:'.$exchange->id, $key, 'admin');
        $legacyHash = hash('sha256', $key);
        $fingerprint = $this->replacementShipmentFingerprint($exchange, $data);

        return DB::transaction(function () use ($exchange, $data, $hash, $legacyHash, $fingerprint) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            $existing = $this->existingShipment($locked, $hash, $legacyHash, 'replacement');
            if ($existing !== null) {
                $this->assertShipmentFingerprint($existing, $fingerprint, $locked, 'replacement');

                return $locked->fresh();
            }
            if ($locked->status !== 'replacement_pending') {
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
                'request_fingerprint_hash' => $fingerprint,
                'shipped_at' => now(),
            ]);
            $isLogistics = $data['fulfillment_type'] === 'logistics';
            $locked->update([
                'status' => $isLogistics ? 'replacement_shipped' : 'completed',
                'replacement_auto_complete_at' => $isLogistics ? now()->addDays((int) $this->settings->read('after_sales.user_action_days')['value']) : null,
                'completed_at' => $isLogistics ? null : now(),
            ]);

            return $locked->fresh();
        });
    }

    public function closeExpired(int $limit = 100): int
    {
        $closed = 0;
        Exchange::query()->where('status', 'waiting_return')->where('return_deadline_at', '<=', now())->limit($limit)->get()
            ->each(function (Exchange $exchange) use (&$closed): void {
                $closed += Exchange::query()->whereKey($exchange->id)->where('status', 'waiting_return')
                    ->update(['status' => 'closed', 'review_note' => '用户未在售后操作期限内提交寄回物流。']);
            });

        $completed = 0;
        Exchange::query()->where('status', 'replacement_shipped')->where('replacement_auto_complete_at', '<=', now())->limit(max(0, $limit - $closed))->get()
            ->each(function (Exchange $exchange) use (&$completed): void {
                DB::transaction(function () use ($exchange, &$completed): void {
                    $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
                    if ($locked->status !== 'replacement_shipped' || $locked->replacement_auto_complete_at?->isFuture()) {
                        return;
                    }
                    ExchangeShipment::query()->where('exchange_id', $locked->id)->where('direction', 'replacement')
                        ->where('status', 'shipped')->update(['status' => 'received', 'received_at' => now()]);
                    $locked->update(['status' => 'completed', 'completed_at' => now(), 'replacement_auto_complete_at' => null, 'review_note' => '用户未在期限内确认，系统已自动完成换货。']);
                    $completed++;
                });
            });

        return $closed + $completed;
    }

    public function confirmReplacement(User $user, Exchange $exchange): Exchange
    {
        abort_unless($exchange->user_id === $user->id, 404);

        return DB::transaction(function () use ($exchange) {
            $locked = Exchange::query()->lockForUpdate()->findOrFail($exchange->id);
            if ($locked->status === 'completed') {
                return $locked;
            }
            if ($locked->status !== 'replacement_shipped') {
                throw ValidationException::withMessages(['exchange' => '替换商品尚未发出，暂不能确认完成。']);
            }
            ExchangeShipment::query()->where('exchange_id', $locked->id)->where('direction', 'replacement')->update(['status' => 'received', 'received_at' => now()]);
            $locked->update(['status' => 'completed', 'completed_at' => now(), 'replacement_auto_complete_at' => null]);

            return $locked->fresh();
        });
    }

    private function idempotencyKeyHash(int $actorId, string $operation, string $key, string $actorType = 'user'): string
    {
        return hash('sha256', $actorType.':'.$actorId.':'.$operation.':'.trim($key));
    }

    private function createFingerprint(array $data): string
    {
        $evidence = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($data['evidence_file_ids'] ?? []),
        ), static fn (string $value): bool => $value !== '')));
        sort($evidence, SORT_STRING);

        return $this->hashPayload([
            'operation' => 'exchange.create',
            'order_item_id' => trim((string) ($data['order_item_id'] ?? '')),
            'quantity' => (int) ($data['quantity'] ?? 0),
            'reason_code' => strtolower(trim((string) ($data['reason_code'] ?? ''))),
            'return_method' => strtolower(trim((string) ($data['return_method'] ?? ''))),
            'description' => $this->nullableString($data['description'] ?? null),
            'evidence_file_ids' => $evidence,
        ]);
    }

    private function legacyExchangeFingerprint(Exchange $exchange): ?string
    {
        $orderItemId = OrderItem::query()->whereKey($exchange->order_item_id)->value('public_id');
        if (! is_string($orderItemId) || $orderItemId === '') {
            return null;
        }

        return $this->createFingerprint([
            'order_item_id' => $orderItemId,
            'quantity' => $exchange->quantity,
            'reason_code' => $exchange->reason_code,
            'return_method' => $exchange->return_method,
            'description' => $exchange->description,
            'evidence_file_ids' => $exchange->evidence_file_ids ?? [],
        ]);
    }

    private function existingExchange(User $user, string $idempotencyHash, string $legacyHash): ?Exchange
    {
        $existing = Exchange::query()->where('idempotency_key_hash', $idempotencyHash)->lockForUpdate()->first();
        if ($existing !== null) {
            abort_unless($existing->user_id === $user->id, 404);

            return $existing;
        }

        if ($legacyHash !== $idempotencyHash) {
            $legacy = Exchange::query()->where('idempotency_key_hash', $legacyHash)->lockForUpdate()->first();
            if ($legacy?->user_id === $user->id) {
                return $legacy;
            }
        }

        return null;
    }

    private function assertExchangeFingerprint(Exchange $exchange, string $fingerprint): void
    {
        $stored = $exchange->request_fingerprint_hash;
        if (! is_string($stored) || $stored === '') {
            $stored = $this->legacyExchangeFingerprint($exchange);
        }
        if (is_string($stored) && $stored !== '' && ! hash_equals($stored, $fingerprint)) {
            throw new IdempotencyConflictException('相同幂等键已绑定不同的换货请求，请更换幂等键。');
        }
        if ($exchange->request_fingerprint_hash === null || $exchange->request_fingerprint_hash === '') {
            $exchange->update(['request_fingerprint_hash' => $fingerprint]);
        }
    }

    private function returnShipmentFingerprint(Exchange $exchange, array $data): string
    {
        return $this->hashPayload([
            'operation' => 'exchange.return-shipment',
            'exchange_id' => (string) $exchange->public_id,
            'carrier_code' => strtolower(trim((string) ($data['carrier_code'] ?? ''))),
            'tracking_no' => trim((string) ($data['tracking_no'] ?? '')),
            'paid_shipping_amount' => (int) ($data['paid_shipping_amount'] ?? 0),
            'payment_proof_file_id' => $this->nullableString($data['payment_proof_file_id'] ?? null),
        ]);
    }

    private function replacementShipmentFingerprint(Exchange $exchange, array $data): string
    {
        return $this->hashPayload([
            'operation' => 'exchange.replacement-fulfillment',
            'exchange_id' => (string) $exchange->public_id,
            'fulfillment_type' => strtolower(trim((string) ($data['fulfillment_type'] ?? ''))),
            'carrier_code' => $this->nullableLowerString($data['carrier_code'] ?? null),
            'tracking_no' => $this->nullableString($data['tracking_no'] ?? null),
            'no_logistics_note' => $this->nullableString($data['no_logistics_note'] ?? null),
        ]);
    }

    private function legacyShipmentFingerprint(ExchangeShipment $shipment, Exchange $exchange, string $direction): string
    {
        if ($direction === 'return') {
            return $this->returnShipmentFingerprint($exchange, [
                'carrier_code' => $shipment->carrier_code,
                'tracking_no' => $shipment->tracking_no,
                'paid_shipping_amount' => $shipment->paid_shipping_amount,
                'payment_proof_file_id' => $shipment->payment_proof_file_id
                    ? UploadedFile::query()->whereKey($shipment->payment_proof_file_id)->value('public_id')
                    : null,
            ]);
        }

        return $this->replacementShipmentFingerprint($exchange, [
            'fulfillment_type' => $shipment->method,
            'carrier_code' => $shipment->carrier_code,
            'tracking_no' => $shipment->tracking_no,
            'no_logistics_note' => $shipment->no_logistics_note,
        ]);
    }

    private function existingShipment(Exchange $exchange, string $idempotencyHash, string $legacyHash, string $direction): ?ExchangeShipment
    {
        $existing = ExchangeShipment::query()
            ->where('exchange_id', $exchange->id)
            ->where('direction', $direction)
            ->where('idempotency_key_hash', $idempotencyHash)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($legacyHash !== $idempotencyHash) {
            return ExchangeShipment::query()
                ->where('exchange_id', $exchange->id)
                ->where('direction', $direction)
                ->where('idempotency_key_hash', $legacyHash)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    private function assertShipmentFingerprint(ExchangeShipment $shipment, string $fingerprint, Exchange $exchange, string $direction): void
    {
        $stored = $shipment->request_fingerprint_hash;
        if (! is_string($stored) || $stored === '') {
            $stored = $this->legacyShipmentFingerprint($shipment, $exchange, $direction);
        }
        if ($stored !== '' && ! hash_equals($stored, $fingerprint)) {
            throw new IdempotencyConflictException('相同幂等键已绑定不同的换货物流请求，请更换幂等键。');
        }
        if ($shipment->request_fingerprint_hash === null || $shipment->request_fingerprint_hash === '') {
            $shipment->update(['request_fingerprint_hash' => $fingerprint]);
        }
    }

    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function nullableLowerString(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return $normalized === '' ? null : $normalized;
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
