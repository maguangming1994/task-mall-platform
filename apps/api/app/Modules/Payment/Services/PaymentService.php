<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService
{
    public function __construct(private readonly WechatPaymentGateway $wechat, private readonly OrderSettlementService $settlement) {}

    public function create(Order $order, User $user, string $scene, string $idempotencyKey): Payment
    {
        abort_unless($order->user_id === $user->id, 404);
        $hash = hash('sha256', $idempotencyKey);
        $existing = Payment::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing) {
            abort_unless($existing->user_id === $user->id && $existing->order_id === $order->id, 404);

            return $existing;
        }
        if ($order->status !== 'pending_payment' || $order->payable_cash_amount < 1 || $order->expires_at?->isPast()) {
            throw ValidationException::withMessages(['order' => '订单当前不可支付。']);
        }
        $channel = $order->cash_payment_method;
        if (! in_array($channel, ['wallet', 'wechat'], true)) {
            throw ValidationException::withMessages(['order' => '订单现金资金来源无效。']);
        }

        if ($channel === 'wallet') {
            return DB::transaction(function () use ($order, $user, $scene, $hash) {
                $payment = $this->newPayment($order, $user, 'wallet', $scene, $hash);
                $this->settlement->settleWallet($order, $user);
                $payment->update(['status' => 'succeeded', 'succeeded_at' => now(), 'client_payload' => ['kind' => 'wallet', 'completed' => true]]);
                $this->attempt($payment, 'succeeded', ['channel' => 'wallet'], ['completed' => true]);

                return $payment->fresh();
            });
        }

        $payment = $this->newPayment($order, $user, 'wechat', $scene, $hash);
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
    }

    /** @return array<string,mixed> */
    public function present(Payment $payment): array
    {
        return ['id' => $payment->public_id, 'payment_no' => $payment->payment_no, 'order_id' => $payment->order?->public_id ?? $payment->order()->value('public_id'), 'channel' => $payment->channel, 'scene' => $payment->scene, 'cash_amount' => $payment->cash_amount, 'status' => $payment->status, 'client_payload' => $payment->client_payload, 'expires_at' => $payment->expires_at->toIso8601String(), 'succeeded_at' => $payment->succeeded_at?->toIso8601String(), 'created_at' => $payment->created_at->toIso8601String()];
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
