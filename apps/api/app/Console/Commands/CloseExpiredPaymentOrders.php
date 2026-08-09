<?php

namespace App\Console\Commands;

use App\Modules\Order\Models\Order;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\OrderPaymentReleaseService;
use Illuminate\Console\Command;

class CloseExpiredPaymentOrders extends Command
{
    protected $signature = 'orders:close-expired-payments {--limit=100}';

    protected $description = 'Close expired pending orders after closing their provider payment.';

    public function handle(WechatPaymentGateway $gateway, OrderPaymentReleaseService $release): int
    {
        Order::query()->where('status', 'pending_payment')->where('expires_at', '<=', now())->orderBy('id')->limit((int) $this->option('limit'))->get()->each(function (Order $order) use ($gateway, $release) {
            $payment = Payment::query()->where('order_id', $order->id)->where('status', 'pending')->latest('id')->first();
            try {
                if ($payment) {
                    $gateway->close($payment);
                    $payment->update(['status' => 'closed', 'closed_at' => now()]);
                }
                $release->close($order, 'payment_timeout', '订单支付超时，释放库存、积分和优惠券。');
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        return self::SUCCESS;
    }
}
