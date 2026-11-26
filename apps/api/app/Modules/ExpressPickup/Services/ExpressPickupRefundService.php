<?php

namespace App\Modules\ExpressPickup\Services;

use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Refund\Models\Refund;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 快递代取退款：钱包订单沿用钱包入账，微信订单走微信退款。
 *
 * 代取单不在 refunds 表中建行（该表 order_id 非空且指向商城订单），
 * 退款单号与状态直接记录在 express_pickup_orders 上。
 */
class ExpressPickupRefundService
{
    public function __construct(private readonly WechatPaymentGateway $wechat) {}

    /**
     * 事务内调用：只登记退款单号与待提交状态，不发外部请求。
     */
    public function prepareWechatRefundLocked(ExpressPickupOrder $order): void
    {
        if ($order->refund_no !== null) {
            return;
        }
        $order->forceFill([
            'refund_no' => 'EPR'.$order->order_no,
            'refund_status' => ExpressPickupOrder::REFUND_STATUS_PENDING,
        ])->save();
    }

    /**
     * 事务提交后调用：把待提交的微信退款提交给渠道。
     */
    public function dispatch(ExpressPickupOrder $order): void
    {
        $order = $order->fresh();
        if ($order === null || $order->payment_method !== 'wechat' || $order->refunded_at !== null) {
            return;
        }
        if (! in_array($order->refund_status, [ExpressPickupOrder::REFUND_STATUS_PENDING, ExpressPickupOrder::REFUND_STATUS_FAILED], true)) {
            return;
        }
        $payment = $this->succeededPayment($order);
        if ($payment === null) {
            $this->markFailed($order, '没有找到该代取单已成功的微信支付单。');

            return;
        }

        try {
            $result = $this->wechat->refund($payment, $this->transientRefund($order, $payment));
            $this->applyResult($order, $result);
        } catch (Throwable $exception) {
            $this->markFailed($order, $exception->getMessage());
        }
    }

    public function sync(ExpressPickupOrder $order): void
    {
        if ($order->refund_no === null || $order->refunded_at !== null) {
            return;
        }
        try {
            $result = $this->wechat->queryRefund($this->transientRefund($order, null));
            $this->applyResult($order, $result);
        } catch (Throwable $exception) {
            Log::warning('快递代取微信退款查询失败', [
                'order' => $order->public_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function syncPending(int $limit = 100): int
    {
        $count = 0;
        ExpressPickupOrder::query()
            ->where('payment_method', 'wechat')
            ->whereNotNull('refund_no')
            ->whereIn('refund_status', [ExpressPickupOrder::REFUND_STATUS_PENDING, ExpressPickupOrder::REFUND_STATUS_PROCESSING])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ExpressPickupOrder $order) use (&$count): void {
                try {
                    $this->sync($order);
                    $count++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $count;
    }

    /**
     * 微信退款通知兜底入口；返回 false 表示该退款单不属于代取业务。
     *
     * @param  array<string, mixed>  $result
     */
    public function handleProviderResult(array $result): bool
    {
        $order = ExpressPickupOrder::query()
            ->where('refund_no', (string) ($result['refund_no'] ?? ''))
            ->first();
        if ($order === null) {
            return false;
        }
        $this->applyResult($order, $result);

        return true;
    }

    /**
     * 取消未支付订单时关闭在途微信支付，避免取消后仍被扣款。
     */
    public function closePendingPayments(ExpressPickupOrder $order): void
    {
        $payments = Payment::query()
            ->where('express_pickup_order_id', $order->id)
            ->where('channel', 'wechat')
            ->whereIn('status', ['pending', 'processing'])
            ->get();
        foreach ($payments as $payment) {
            try {
                $this->wechat->close($payment);
                $payment->update(['status' => 'closed', 'closed_at' => now()]);
            } catch (Throwable $exception) {
                Log::warning('快递代取关单失败', [
                    'order' => $order->public_id,
                    'payment' => $payment->payment_no,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyResult(ExpressPickupOrder $order, array $result): void
    {
        $state = strtoupper((string) ($result['refund_state'] ?? ''));
        if ($state === 'SUCCESS') {
            $order->forceFill([
                'refund_status' => ExpressPickupOrder::REFUND_STATUS_REFUNDED,
                'refunded_at' => $order->refunded_at ?? now(),
            ])->save();

            return;
        }
        if (in_array($state, ['PENDING', 'PROCESSING', 'ACCEPTED'], true)) {
            $order->forceFill(['refund_status' => ExpressPickupOrder::REFUND_STATUS_PROCESSING])->save();

            return;
        }
        if (in_array($state, ['ABNORMAL', 'CLOSED', 'FAILED'], true)) {
            $this->markFailed($order, '微信退款返回终态失败：'.$state);

            return;
        }

        Log::warning('快递代取微信退款返回未知状态', [
            'order' => $order->public_id,
            'state' => $state,
        ]);
    }

    private function markFailed(ExpressPickupOrder $order, string $message): void
    {
        if ($order->refunded_at !== null) {
            return;
        }
        $order->forceFill(['refund_status' => ExpressPickupOrder::REFUND_STATUS_FAILED])->save();
        Log::warning('快递代取微信退款失败', [
            'order' => $order->public_id,
            'error' => mb_substr($message, 0, 480),
        ]);
    }

    private function transientRefund(ExpressPickupOrder $order, ?Payment $payment): Refund
    {
        return new Refund([
            'refund_no' => (string) $order->refund_no,
            'refund_external_amount' => (int) ($order->paid_amount_cents ?? $payment?->cash_amount ?? $order->total_cents),
        ]);
    }

    private function succeededPayment(ExpressPickupOrder $order): ?Payment
    {
        return Payment::query()
            ->where('express_pickup_order_id', $order->id)
            ->where('channel', 'wechat')
            ->where('status', 'succeeded')
            ->latest('id')
            ->first();
    }
}
