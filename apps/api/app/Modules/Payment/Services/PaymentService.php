<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Assets\Models\WalletRecharge;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\SecondHand\Models\SecondHandOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly WechatPaymentGateway $wechat,
        private readonly OrderSettlementService $settlement,
        private readonly PaymentCapabilityService $capabilities,
    ) {}

    public function create(Order $order, User $user, string $scene, string $idempotencyKey): Payment
    {
        abort_unless($order->user_id === $user->id, 404);
        $hash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($order, $user, $scene, $hash) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->order_id === $lockedOrder->id, 404);

                return $existing;
            }

            $active = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', ['pending', 'processing'])
                ->latest('id')
                ->first();
            if ($active) {
                if ($active->scene !== $scene) {
                    throw ValidationException::withMessages(['payment' => '该订单已有其他终端发起的支付，请返回原终端继续支付或等待结果。']);
                }

                return $active;
            }

            if ($lockedOrder->status !== 'pending_payment' || $lockedOrder->payable_cash_amount < 1 || $lockedOrder->expires_at?->isPast()) {
                throw ValidationException::withMessages(['order' => '订单当前不可支付。']);
            }
            $channel = $lockedOrder->cash_payment_method;
            if (! in_array($channel, ['wallet', 'wechat'], true)) {
                throw ValidationException::withMessages(['order' => '订单现金资金来源无效。']);
            }
            $capability = $this->capabilities->forPlatform($scene)[$channel];
            if (! $capability['enabled']) {
                throw ValidationException::withMessages(['payment' => $capability['reason'] ?? '当前支付方式不可用。']);
            }

            if ($channel === 'wallet') {
                $payment = $this->newPayment($lockedOrder, $user, 'wallet', $scene, $hash);
                $this->settlement->settleWallet($lockedOrder, $user);
                $payment->update(['status' => 'succeeded', 'succeeded_at' => now(), 'client_payload' => ['kind' => 'wallet', 'completed' => true]]);
                $this->attempt($payment, 'succeeded', ['channel' => 'wallet'], ['completed' => true]);

                return $payment->fresh();
            }

            $payment = $this->newPayment($lockedOrder, $user, 'wechat', $scene, $hash);
            try {
                $payload = $this->wechat->prepare($payment->load('order'), $user);
                $payment->update(['client_payload' => $payload]);
                $this->attempt($payment, 'prepared', ['scene' => $scene, 'amount' => $payment->cash_amount], ['kind' => $payload['kind'] ?? 'unknown']);
            } catch (Throwable $exception) {
                $payment->update(['status' => 'failed']);
                $this->attempt($payment, 'failed', ['scene' => $scene, 'amount' => $payment->cash_amount], null, $exception);
                throw $exception;
            }

            return $payment->fresh();
        });
    }

    public function createRecharge(WalletRecharge $recharge, User $user, string $scene, string $idempotencyKey): Payment
    {
        abort_unless($recharge->user_id === $user->id, 404);
        $hash = hash('sha256', 'wallet-recharge-payment:'.$idempotencyKey);

        return DB::transaction(function () use ($recharge, $user, $scene, $hash): Payment {
            $locked = WalletRecharge::query()->whereKey($recharge->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->wallet_recharge_id === $locked->id, 404);

                return $existing;
            }
            if ($locked->method !== 'online' || $locked->status !== 'pending_payment' || $locked->expires_at?->isPast()) {
                throw ValidationException::withMessages(['recharge' => '充值单已过期或当前不可支付。']);
            }
            $capability = $this->capabilities->forPlatform($scene)['wechat'];
            if (! $capability['enabled']) {
                throw ValidationException::withMessages(['payment' => $capability['reason'] ?? '当前线上支付不可用。']);
            }
            $active = Payment::query()->where('wallet_recharge_id', $locked->id)->whereIn('status', ['pending', 'processing'])->latest('id')->first();
            if ($active) {
                return $active;
            }
            $payment = Payment::query()->create(['payment_no' => 'P'.now()->format('YmdHis').strtoupper(Str::random(10)), 'wallet_recharge_id' => $locked->id, 'user_id' => $user->id, 'channel' => 'wechat', 'scene' => $scene, 'cash_amount' => $locked->amount_cents, 'status' => 'pending', 'idempotency_key_hash' => $hash, 'expires_at' => $locked->expires_at]);
            try {
                $payload = $this->wechat->prepare($payment->load('walletRecharge'), $user);
                $payment->update(['client_payload' => $payload]);
                $this->attempt($payment, 'prepared', ['scene' => $scene, 'recharge_no' => $locked->recharge_no], ['kind' => $payload['kind'] ?? 'unknown']);
            } catch (Throwable $exception) {
                $payment->update(['status' => 'failed']);
                $this->attempt($payment, 'failed', ['scene' => $scene, 'recharge_no' => $locked->recharge_no], null, $exception);
                throw $exception;
            }

            return $payment->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function present(Payment $payment): array
    {
        return ['id' => $payment->public_id, 'payment_no' => $payment->payment_no, 'order_id' => $payment->order?->public_id ?? $payment->order()->value('public_id'), 'wallet_recharge_id' => $payment->walletRecharge?->public_id ?? $payment->walletRecharge()->value('public_id'), 'second_hand_order_id' => $payment->secondHandOrder?->public_id ?? $payment->secondHandOrder()->value('public_id'), 'express_pickup_order_id' => $payment->expressPickupOrder?->public_id ?? $payment->expressPickupOrder()->value('public_id'), 'channel' => $payment->channel, 'scene' => $payment->scene, 'cash_amount' => $payment->cash_amount, 'status' => $payment->status, 'client_payload' => $payment->client_payload, 'expires_at' => $payment->expires_at->toIso8601String(), 'succeeded_at' => $payment->succeeded_at?->toIso8601String(), 'created_at' => $payment->created_at->toIso8601String()];
    }

    public function createSecondHandPayment(SecondHandOrder $order, User $user, string $scene, string $idempotencyKey): Payment
    {
        abort_unless($order->buyer_user_id === $user->id, 404);
        $hash = hash('sha256', 'sh-order-payment:'.$idempotencyKey);

        return DB::transaction(function () use ($order, $user, $scene, $hash): Payment {
            $lockedOrder = SecondHandOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->second_hand_order_id === $lockedOrder->id, 404);

                return $existing;
            }

            $active = Payment::query()
                ->where('second_hand_order_id', $lockedOrder->id)
                ->whereIn('status', ['pending', 'processing'])
                ->latest('id')
                ->first();
            if ($active) {
                if ($active->scene !== $scene) {
                    throw ValidationException::withMessages(['payment' => '该订单已有其他终端发起的支付，请返回原终端继续支付或等待结果。']);
                }

                return $active;
            }

            if ($lockedOrder->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => '订单当前不可支付。']);
            }

            $capability = $this->capabilities->forPlatform($scene)['wechat'];
            if (! $capability['enabled']) {
                throw ValidationException::withMessages(['payment' => $capability['reason'] ?? '当前线上支付不可用。']);
            }

            $payment = Payment::query()->create([
                'payment_no' => 'P'.now()->format('YmdHis').strtoupper(Str::random(10)),
                'second_hand_order_id' => $lockedOrder->id,
                'user_id' => $user->id,
                'channel' => 'wechat',
                'scene' => $scene,
                'cash_amount' => $lockedOrder->price,
                'status' => 'pending',
                'idempotency_key_hash' => $hash,
            ]);

            try {
                $payload = $this->wechat->prepare($payment->load('secondHandOrder'), $user);
                $payment->update(['client_payload' => $payload]);
                $this->attempt($payment, 'prepared', ['scene' => $scene, 'amount' => $payment->cash_amount], ['kind' => $payload['kind'] ?? 'unknown']);
            } catch (Throwable $exception) {
                $payment->update(['status' => 'failed']);
                $this->attempt($payment, 'failed', ['scene' => $scene, 'amount' => $payment->cash_amount], null, $exception);
                throw $exception;
            }

            return $payment->fresh();
        });
    }

    public function createExpressPickupPayment(ExpressPickupOrder $order, User $user, string $scene, string $idempotencyKey): Payment
    {
        abort_unless($order->user_id === $user->id, 404);
        $hash = hash('sha256', 'ep-order-payment:'.$idempotencyKey);

        return DB::transaction(function () use ($order, $user, $scene, $hash): Payment {
            $lockedOrder = ExpressPickupOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->express_pickup_order_id === $lockedOrder->id, 404);

                return $existing;
            }

            $active = Payment::query()
                ->where('express_pickup_order_id', $lockedOrder->id)
                ->whereIn('status', ['pending', 'processing'])
                ->latest('id')
                ->first();
            if ($active) {
                if ($active->scene !== $scene) {
                    throw ValidationException::withMessages(['payment' => '该代取单已有其他终端发起的支付，请返回原终端继续支付或等待结果。']);
                }

                return $active;
            }

            if ($lockedOrder->status !== ExpressPickupOrder::STATUS_PENDING_PAYMENT) {
                throw ValidationException::withMessages(['order' => '代取单当前不可支付。']);
            }

            $capability = $this->capabilities->forPlatform($scene)['wechat'];
            if (! $capability['enabled']) {
                throw ValidationException::withMessages(['payment' => $capability['reason'] ?? '当前线上支付不可用。']);
            }

            $payment = Payment::query()->create([
                'payment_no' => 'P'.now()->format('YmdHis').strtoupper(Str::random(10)),
                'express_pickup_order_id' => $lockedOrder->id,
                'user_id' => $user->id,
                'channel' => 'wechat',
                'scene' => $scene,
                'cash_amount' => $lockedOrder->total_cents,
                'status' => 'pending',
                'idempotency_key_hash' => $hash,
            ]);

            try {
                $payload = $this->wechat->prepare($payment->load('expressPickupOrder'), $user);
                $payment->update(['client_payload' => $payload]);
                $this->attempt($payment, 'prepared', ['scene' => $scene, 'amount' => $payment->cash_amount], ['kind' => $payload['kind'] ?? 'unknown']);
            } catch (Throwable $exception) {
                $payment->update(['status' => 'failed']);
                $this->attempt($payment, 'failed', ['scene' => $scene, 'amount' => $payment->cash_amount], null, $exception);
                throw $exception;
            }

            return $payment->fresh();
        });
    }

    private function newPayment(Order $order, User $user, string $channel, string $scene, string $hash): Payment
    {
        return Payment::query()->create(['payment_no' => 'P'.now()->format('YmdHis').strtoupper(Str::random(10)), 'order_id' => $order->id, 'user_id' => $user->id, 'channel' => $channel, 'scene' => $scene, 'cash_amount' => $order->payable_cash_amount, 'status' => $channel === 'wallet' ? 'processing' : 'pending', 'idempotency_key_hash' => $hash, 'expires_at' => $order->expires_at]);
    }

    private function attempt(Payment $payment, string $status, array $request, ?array $response, ?Throwable $error = null): void
    {
        DB::table('payment_attempts')->insert(['payment_id' => $payment->id, 'attempt_no' => DB::table('payment_attempts')->where('payment_id', $payment->id)->count() + 1, 'status' => $status, 'request_summary' => json_encode($request), 'response_summary' => $response ? json_encode($response) : null, 'error_code' => $error ? class_basename($error) : null, 'error_message' => $error ? Str::limit($error->getMessage(), 500) : null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
