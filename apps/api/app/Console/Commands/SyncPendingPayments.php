<?php

namespace App\Console\Commands;

use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentNotificationService;
use Illuminate\Console\Command;

class SyncPendingPayments extends Command
{
    protected $signature = 'payments:sync-pending {--limit=100}';

    protected $description = 'Query pending WeChat payments and apply authoritative states.';

    public function handle(WechatPaymentGateway $gateway, PaymentNotificationService $notifications): int
    {
        Payment::query()->where('channel', 'wechat')->where('status', 'pending')->orderBy('id')->limit((int) $this->option('limit'))->get()->each(function (Payment $payment) use ($gateway, $notifications) {
            try {
                $notifications->process($gateway->query($payment), ['source' => 'active_query']);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        return self::SUCCESS;
    }
}
