<?php

namespace App\Modules\Refund\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\UserCoupon;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Refund\Exceptions\RefundException;
use App\Modules\Refund\Models\AfterSaleReason;
use App\Modules\Refund\Models\Refund;
use App\Modules\Refund\Models\ReturnShipment;
use App\Modules\Refund\Models\ReturnShippingCompensation;
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
        private readonly WechatPaymentGateway $wechat,
    ) {}

    public function create(User $user, Order $order, array $data, string $key): Refund
    {
        abort_unless($order->user_id === $user->id, 404);
        $hash = hash('sha256', $key);
        $fingerprint = $this->requestFingerprint($order, $data);

        return DB::transaction(function () use ($user, $order, $data, $hash, $fingerprint): Refund {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            // Lock the idempotency row while checking its payload. This keeps
            // retries deterministic when two requests arrive together and
            // lets rows created before request fingerprints remain replayable.
            $existing = Refund::query()->where('idempotency_key_hash', $hash)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($existing->user_id === $user->id, 404);
                if ($existing->request_fingerprint_hash !== null
                    && ! hash_equals((string) $existing->request_fingerprint_hash, $fingerprint)) {
                    throw new RefundException(
                        'REFUND_IDEMPOTENCY_CONFLICT',
                        '幂等键已用于创建另一笔退款申请，请更换幂等键。',
                        409,
                    );
                }
                // A nullable fingerprint is intentional for legacy rows. On
                // their first replay, bind it to the normalized request so
                // later accidental payload changes are still rejected.
                if ($existing->request_fingerprint_hash === null) {
                    $existing->update(['request_fingerprint_hash' => $fingerprint]);
                }

                return $existing->fresh();
            }

            if (! in_array($locked->status, ['pending_fulfillment', 'fulfilled', 'completed', 'partially_refunded'], true)) {
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
                'request_fingerprint_hash' => $fingerprint,
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

    public function reject(AdminUser $admin, Refund $refund, string $note): Refund
    {
        return DB::transaction(function () use ($admin, $refund, $note): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            // Retrying the same terminal decision is safe and returns the
            // original audit record without allowing a note/admin overwrite.
            if ($locked->status === 'rejected') {
                return $locked;
            }
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['refund' => '退款当前已进入后续处理，不能驳回或回退状态。']);
            }

            $locked->update([
                'status' => 'rejected',
                'reviewed_by_admin_id' => $admin->id,
                'review_note' => $note,
            ]);

            return $locked->fresh();
        });
    }

    public function approve(AdminUser $admin, Refund $refund, string $note, array $decision = []): Refund
    {
        $shouldDispatchProvider = false;
        $result = DB::transaction(function () use ($admin, $refund, $note, $decision, &$shouldDispatchProvider): Refund {
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

            $settled = $this->settle($admin, $locked);
            $shouldDispatchProvider = $settled->status === 'processing';

            return $settled;
        });

        return $shouldDispatchProvider ? $this->dispatchProviderRefund($result) : $result;
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
            $shipment = ReturnShipment::query()->create([
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
            if ($compensation > 0) {
                ReturnShippingCompensation::query()->create([
                    'user_id' => $user->id,
                    'source_type' => 'refund_return',
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

    public function receiveReturn(AdminUser $admin, Refund $refund, string $note): Refund
    {
        $shouldDispatchProvider = false;
        $result = DB::transaction(function () use ($admin, $refund, $note, &$shouldDispatchProvider): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if (in_array($locked->status, ['succeeded', 'processing'], true)) {
                return $locked;
            }
            if ($locked->status !== 'return_in_transit') {
                throw ValidationException::withMessages(['refund' => '当前没有待确认收货的退货。']);
            }
            ReturnShipment::query()->where('refund_id', $locked->id)->update(['status' => 'received', 'received_at' => now()]);
            $locked->update(['reviewed_by_admin_id' => $admin->id, 'review_note' => $note]);

            $settled = $this->settle($admin, $locked);
            $shouldDispatchProvider = $settled->status === 'processing';

            return $settled;
        });

        return $shouldDispatchProvider ? $this->dispatchProviderRefund($result) : $result;
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

    /** @param array<string,string|null> $headers */
    public function handleProviderNotification(array $headers, string $body): void
    {
        $this->processProviderResult($this->wechat->parseRefundNotification($headers, $body));
    }

    public function dispatchProviderRefund(Refund $refund): Refund
    {
        $refund = Refund::query()->findOrFail($refund->id);
        if ($refund->status === 'succeeded') {
            return $refund;
        }
        $component = DB::table('refund_components')->where('refund_id', $refund->id)->where('component_type', 'external')->first();
        if ($refund->status !== 'processing' || $component === null || ! in_array($component->status, ['pending_provider', 'processing', 'failed'], true)) {
            throw ValidationException::withMessages(['refund' => '退款当前不处于可提交外部渠道的状态。']);
        }
        $payment = Payment::query()->where('order_id', $refund->order_id)->where('channel', 'wechat')->where('status', 'succeeded')->latest('id')->first();
        if ($payment === null) {
            throw ValidationException::withMessages(['refund' => '没有找到该订单已成功的微信支付单，已阻止外部退款。']);
        }

        try {
            $result = $this->wechat->refund($payment, $refund);
            $this->processProviderResult($result);
        } catch (\Throwable $exception) {
            DB::table('refund_components')->where('id', $component->id)->update([
                'retry_count' => DB::raw('retry_count + 1'),
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
            $this->recordProviderFailure($refund, 'submit', $exception);
            throw $exception;
        }

        return $refund->fresh();
    }

    public function syncProviderRefund(Refund $refund): Refund
    {
        if ($refund->status === 'succeeded') {
            return $refund->fresh();
        }
        try {
            $this->processProviderResult($this->wechat->queryRefund($refund));
        } catch (\Throwable $exception) {
            DB::table('refund_components')->where('refund_id', $refund->id)->where('component_type', 'external')->update([
                'retry_count' => DB::raw('retry_count + 1'),
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
            $this->recordProviderFailure($refund, 'query', $exception);
            throw $exception;
        }

        return $refund->fresh();
    }

    public function syncPendingProviderRefunds(int $limit = 100): int
    {
        $count = 0;
        Refund::query()->where('status', 'processing')->where('refund_external_amount', '>', 0)->orderBy('id')->limit($limit)->get()
            ->each(function (Refund $refund) use (&$count): void {
                try {
                    $this->syncProviderRefund($refund);
                    $count++;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

        return $count;
    }

    /** @param array<string,mixed> $result */
    public function processProviderResult(array $result): void
    {
        $eventId = trim((string) ($result['notification_id'] ?? ''));
        if ($eventId === '') {
            throw ValidationException::withMessages(['refund' => '退款渠道结果缺少事件编号。']);
        }
        $refund = Refund::query()->where('refund_no', (string) ($result['refund_no'] ?? ''))->first();
        if ($refund === null) {
            throw ValidationException::withMessages(['refund' => '退款渠道结果对应的退款单不存在。']);
        }
        $event = [
            'provider' => 'wechat',
            'event_id' => $eventId,
            'refund_id' => $refund->id,
            'event_type' => (string) ($result['event_type'] ?? ''),
            'provider_state' => strtoupper((string) ($result['refund_state'] ?? '')),
            'provider_reference' => $result['provider_refund_no'] ?? null,
            'resource_hash' => hash('sha256', json_encode($result['raw_resource'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'status' => 'received',
            'occurred_at' => $result['success_time'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (DB::table('refund_provider_events')->insertOrIgnore($event) === 0) {
            $existing = DB::table('refund_provider_events')->where(['provider' => 'wechat', 'event_id' => $eventId])->first();
            if ($existing?->status === 'processed') {
                return;
            }
            DB::table('refund_provider_events')->where('id', $existing->id)->update(['status' => 'received', 'error_message' => null, 'updated_at' => now()]);
        }
        $eventDbId = DB::table('refund_provider_events')->where(['provider' => 'wechat', 'event_id' => $eventId])->value('id');

        try {
            $amount = (int) ($result['refund_amount'] ?? 0);
            if ($amount > 0 && $amount !== $refund->refund_external_amount) {
                throw ValidationException::withMessages(['refund' => '退款渠道返回金额与本地退款单不一致。']);
            }
            $state = strtoupper((string) ($result['refund_state'] ?? ''));
            $reference = trim((string) ($result['provider_refund_no'] ?? ''));
            if ($state === 'SUCCESS') {
                $this->completeProviderRefund($refund, $reference);
            } elseif (in_array($state, ['PROCESSING', 'ACCEPTED'], true)) {
                DB::transaction(function () use ($refund, $reference): void {
                    $component = DB::table('refund_components')->where('refund_id', $refund->id)->where('component_type', 'external')->lockForUpdate()->first();
                    if ($component === null) {
                        throw ValidationException::withMessages(['refund' => '外部退款组成项不存在。']);
                    }
                    if ($component->provider_refund_no !== null && $reference !== '' && $component->provider_refund_no !== $reference) {
                        throw ValidationException::withMessages(['refund' => '退款渠道流水号发生冲突。']);
                    }
                    DB::table('refund_components')->where('id', $component->id)->update([
                        'status' => 'processing',
                        'provider_refund_no' => $reference !== '' ? $reference : $component->provider_refund_no,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
                });
            } elseif (in_array($state, ['ABNORMAL', 'CLOSED', 'FAILED'], true)) {
                DB::table('refund_components')->where('refund_id', $refund->id)->where('component_type', 'external')->update([
                    'status' => 'failed',
                    'provider_refund_no' => $reference !== '' ? $reference : null,
                    'error_message' => '退款渠道返回终态失败：'.$state,
                    'updated_at' => now(),
                ]);
            } elseif ($state !== 'PENDING') {
                throw ValidationException::withMessages(['refund' => '退款渠道返回了无法识别的状态。']);
            }
            DB::table('refund_provider_events')->where('id', $eventDbId)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('refund_provider_events')->where('id', $eventDbId)->update(['status' => 'rejected', 'error_message' => mb_substr($exception->getMessage(), 0, 500), 'processed_at' => now(), 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function recordProviderFailure(Refund $refund, string $operation, \Throwable $exception): void
    {
        $attempt = (int) DB::table('refund_components')->where('refund_id', $refund->id)->where('component_type', 'external')->value('retry_count');
        DB::table('refund_provider_events')->insertOrIgnore([
            'provider' => 'wechat',
            'event_id' => "{$operation}-error-{$refund->refund_no}-{$attempt}",
            'refund_id' => $refund->id,
            'event_type' => 'REFUND.'.strtoupper($operation).'.ERROR',
            'provider_state' => null,
            'provider_reference' => null,
            'resource_hash' => hash('sha256', get_class($exception).':'.$exception->getCode()),
            'status' => 'rejected',
            'error_message' => mb_substr($exception->getMessage(), 0, 500),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Complete local settlement only after the external refund provider has
     * confirmed success. Retries with the same provider refund number are
     * idempotent; a conflicting provider result is rejected.
     */
    public function completeProviderRefund(Refund $refund, string $providerRefundNo): Refund
    {
        if (trim($providerRefundNo) === '') {
            throw ValidationException::withMessages(['provider_refund_no' => '外部退款流水号不能为空。']);
        }

        return DB::transaction(function () use ($refund, $providerRefundNo): Refund {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $component = DB::table('refund_components')
                ->where('refund_id', $locked->id)
                ->where('component_type', 'external')
                ->lockForUpdate()
                ->first();
            if ($component === null) {
                throw ValidationException::withMessages(['refund' => '该退款没有待处理的外部退款组成项。']);
            }
            if ($component->provider_refund_no !== null && $component->provider_refund_no !== $providerRefundNo) {
                throw ValidationException::withMessages(['provider_refund_no' => '外部退款流水号与已记录结果不一致。']);
            }
            if ($locked->status === 'succeeded' && $component->status === 'succeeded') {
                return $locked;
            }
            if ($locked->status !== 'processing' || ! in_array($component->status, ['pending_provider', 'processing', 'failed'], true)) {
                throw ValidationException::withMessages(['refund' => '退款当前不处于等待外部渠道确认的状态。']);
            }

            DB::table('refund_components')->where('id', $component->id)->update([
                'status' => 'succeeded',
                'provider_refund_no' => $providerRefundNo,
                'error_message' => null,
                'updated_at' => now(),
            ]);
            $admin = $locked->reviewed_by_admin_id
                ? AdminUser::query()->find($locked->reviewed_by_admin_id)
                : null;

            return $this->finalizeSettlement($locked, $admin);
        });
    }

    private function settle(AdminUser $admin, Refund $refund): Refund
    {
        if ($refund->refund_external_amount > 0) {
            DB::table('refund_components')->updateOrInsert(
                ['refund_id' => $refund->id, 'component_type' => 'external'],
                ['amount' => $refund->refund_external_amount, 'status' => 'pending_provider', 'updated_at' => now(), 'created_at' => now()],
            );
            $refund->update(['status' => 'processing']);

            return $refund->fresh();
        }

        return $this->finalizeSettlement($refund, $admin);
    }

    private function finalizeSettlement(Refund $refund, ?AdminUser $admin): Refund
    {
        $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
        if ($refund->status === 'succeeded') {
            return $refund;
        }

        $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
        $user = User::query()->findOrFail($refund->user_id);
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
        $newExternal = $order->refunded_external_amount + $refund->refund_external_amount;
        $newPoints = $order->refunded_points + $refund->refund_points;
        $fullyRefunded = $newWallet + $newExternal >= $order->paid_wallet_amount + $order->paid_external_amount
            && $newPoints >= $order->paid_points;
        $order->update([
            'refunded_wallet_amount' => $newWallet,
            'refunded_external_amount' => $newExternal,
            'refunded_points' => $newPoints,
            'status' => $fullyRefunded ? 'refunded' : 'partially_refunded',
        ]);

        $couponReturned = false;
        if ($order->user_coupon_id !== null && $this->couponReturnEnabled($fullyRefunded)) {
            $coupon = UserCoupon::query()->find($order->user_coupon_id);
            if ($coupon !== null) {
                $couponReturned = $this->assets->returnUsedCoupon(
                    $coupon,
                    $refund->public_id,
                    'refund:'.$refund->public_id,
                    $admin,
                );
            }
        }
        $refund->update([
            'status' => 'succeeded',
            'coupon_returned' => $couponReturned,
            'completed_at' => now(),
        ]);

        return $refund->fresh();
    }

    private function couponReturnEnabled(bool $fullyRefunded): bool
    {
        $setting = $fullyRefunded
            ? 'coupons.full_refund_returns_coupon'
            : 'coupons.partial_refund_returns_coupon';

        return (bool) $this->settings->read($setting)['value'];
    }

    /**
     * Build a stable fingerprint from the effective refund request rather than
     * PHP array insertion order. Item order and evidence order are not
     * meaningful, while the effective service/return method is.
     *
     * @param  array<string,mixed>  $data
     */
    private function requestFingerprint(Order $order, array $data): string
    {
        $serviceType = (string) ($data['service_type'] ?? 'refund_only');
        $returnMethod = $serviceType === 'return_and_refund'
            ? (string) ($data['return_method'] ?? ($order->fulfillment_type === 'logistics' ? 'logistics' : 'no_logistics'))
            : null;
        $items = array_map(static fn (array $row): array => [
            'order_item_id' => trim((string) ($row['order_item_id'] ?? '')),
            'quantity' => (int) ($row['quantity'] ?? 0),
        ], array_values((array) ($data['items'] ?? [])));
        usort($items, static function (array $left, array $right): int {
            $idComparison = strcmp($left['order_item_id'], $right['order_item_id']);

            return $idComparison !== 0 ? $idComparison : $left['quantity'] <=> $right['quantity'];
        });
        $evidence = array_values(array_unique(array_map('strval', (array) ($data['evidence_file_ids'] ?? []))));
        sort($evidence, SORT_STRING);
        $description = trim((string) ($data['description'] ?? ''));
        $payload = [
            'order_id' => (string) $order->public_id,
            'service_type' => $serviceType,
            'reason_code' => trim((string) ($data['reason_code'] ?? '')),
            'return_method' => $returnMethod,
            'description' => $description === '' ? null : $description,
            'evidence_file_ids' => $evidence,
            'items' => $items,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
            $pendingQuery = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                ->where('order_item_id', $item->id)
                ->whereIn('refunds.status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing']);
            $pendingQuantity = (clone $pendingQuery)->sum('quantity');
            $pendingCash = (clone $pendingQuery)->sum('refund_items.refund_cash_amount');
            $pendingPoints = (clone $pendingQuery)->sum('refund_items.refund_points');
            $activeExchangeQuantity = DB::table('exchanges')->where('order_item_id', $item->id)
                ->whereNotIn('status', ['rejected', 'closed', 'cancelled'])->sum('quantity');
            $remaining = $item->quantity - $item->refunded_quantity - $pendingQuantity - $activeExchangeQuantity;
            if ($row['quantity'] > $remaining) {
                throw ValidationException::withMessages(['quantity' => '退货/退款数量超过剩余可退数量。']);
            }
            $remainingCash = $item->paid_cash_amount - $item->refunded_cash_amount - $pendingCash;
            $remainingPoints = $item->paid_points - $item->refunded_points - $pendingPoints;
            $isFinalQuantity = $activeExchangeQuantity === 0 && $row['quantity'] === $remaining;
            $itemCash = $isFinalQuantity
                ? $remainingCash
                : min($remainingCash, intdiv($item->paid_cash_amount * $row['quantity'], $item->quantity));
            $itemPoints = $isFinalQuantity
                ? $remainingPoints
                : min($remainingPoints, intdiv($item->paid_points * $row['quantity'], $item->quantity));
            $cash += $itemCash;
            $points += $itemPoints;
            $rows[] = [$item, $row['quantity'], $itemCash, $itemPoints, $remaining];
        }

        return [$cash, $points, $rows];
    }
}
