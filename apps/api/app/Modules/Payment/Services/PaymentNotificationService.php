<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Assets\Services\WalletRechargeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentNotificationService
{
    public function __construct(private readonly WechatPaymentGateway $gateway, private readonly OrderSettlementService $settlement, private readonly OrderPaymentReleaseService $release, private readonly WalletRechargeService $recharges) {}

    /** @param array<string,string|null> $headers */
    public function handle(array $headers, string $body): void
    {
        $trade = $this->gateway->parseNotification($headers, $body);
        $this->process($trade, ['serial' => $headers['serial'] ?? null, 'timestamp' => $headers['timestamp'] ?? null]);
    }

    /** @param array<string,mixed> $trade */
    public function process(array $trade, array $headersSummary = []): void
    {
        $notificationId = (string) ($trade['notification_id'] ?? '');
        if ($notificationId === '') {
            return;
        }
        $notificationRow = ['provider' => 'wechat', 'notification_id' => $notificationId, 'event_type' => (string) ($trade['event_type'] ?? ''), 'trade_state' => (string) ($trade['trade_state'] ?? ''), 'resource_hash' => hash('sha256', json_encode($trade['raw_resource'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'headers_summary' => json_encode($headersSummary), 'status' => 'received', 'occurred_at' => $trade['success_time'] ?? null, 'created_at' => now(), 'updated_at' => now()];
        if (DB::table('payment_notifications')->insertOrIgnore($notificationRow) === 0) {
            return;
        }
        $notificationDbId = DB::table('payment_notifications')->where(['provider' => 'wechat', 'notification_id' => $notificationId])->value('id');
        try {
            $payment = Payment::query()->where('payment_no', $trade['payment_no'] ?? '')->first();
            if (! $payment) {
                throw ValidationException::withMessages(['payment' => '通知对应的支付单不存在。']);
            }
            DB::table('payment_notifications')->where('id', $notificationDbId)->update(['payment_id' => $payment->id]);
            $state = (string) ($trade['trade_state'] ?? '');
            if ($state === 'SUCCESS') {
                if ((int) ($trade['cash_amount'] ?? 0) !== $payment->cash_amount) {
                    throw ValidationException::withMessages(['payment' => '支付通知金额与支付单不一致。']);
                }
                DB::transaction(function () use ($payment, $trade) {
                    $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                    if ($locked->status === 'succeeded') {
                        return;
                    }
                    if (! in_array($locked->status, ['pending', 'processing'], true)) {
                        throw ValidationException::withMessages(['payment' => '终态支付单不能重新置为成功。']);
                    }
                    $user = User::query()->findOrFail($locked->user_id);
                    if ($locked->wallet_recharge_id) $this->recharges->settleOnline($locked->walletRecharge);
                    else $this->settlement->settleExternal($locked->order, $user, $locked->cash_amount);
                    $locked->update(['status' => 'succeeded', 'provider_trade_no' => $trade['provider_trade_no'] ?? null, 'succeeded_at' => $trade['success_time'] ?? now()]);
                });
            } elseif (in_array($state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                DB::transaction(function () use ($payment, $state) {
                    $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                    if ($locked->status === 'succeeded') {
                        return;
                    }
                    $locked->update(['status' => $state === 'PAYERROR' ? 'failed' : 'closed', 'closed_at' => now()]);
                    if ($locked->wallet_recharge_id) $this->recharges->closeOnline($locked->walletRecharge, $state === 'PAYERROR' ? 'cancelled' : 'expired');
                    else $this->release->close($locked->order, 'payment_provider_closed', '微信支付渠道确认交易关闭，释放订单预占资源。');
                });
            }
            DB::table('payment_notifications')->where('id', $notificationDbId)->update(['status' => 'processed', 'processed_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            DB::table('payment_notifications')->where('id', $notificationDbId)->update(['status' => 'rejected', 'error_message' => mb_substr($exception->getMessage(), 0, 500), 'processed_at' => now(), 'updated_at' => now()]);
            throw $exception;
        }
    }
}
