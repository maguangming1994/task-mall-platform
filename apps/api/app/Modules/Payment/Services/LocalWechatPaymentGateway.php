<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;

class LocalWechatPaymentGateway implements WechatPaymentGateway
{
    public function prepare(Payment $payment, User $user): array
    {
        abort_unless(app()->environment(['local', 'testing']), 503, 'Local payment gateway is disabled.');
        $prepayId = 'local_'.strtolower($payment->payment_no);

        if ($payment->scene === 'h5-browser') {
            return ['kind' => 'mweb', 'mweb_url' => "https://pay.local.invalid/{$prepayId}", 'sandbox' => true];
        }

        return [
            'kind' => $payment->scene === 'wechat-mini' ? 'mini_program' : 'jsapi',
            'appId' => 'local-app-id', 'timeStamp' => (string) now()->timestamp,
            'nonceStr' => substr(hash('sha256', $payment->payment_no), 0, 24),
            'package' => 'prepay_id='.$prepayId, 'signType' => 'RSA',
            'paySign' => base64_encode(hash('sha256', $payment->payment_no, true)), 'sandbox' => true,
        ];
    }

    public function parseNotification(array $headers, string $body): array
    {
        abort_unless(app()->environment(['local', 'testing']) && hash_equals('local-test-signature', (string) ($headers['signature'] ?? '')), 401, 'Invalid local payment signature.');
        $payload = json_decode($body, true);
        abort_unless(is_array($payload) && is_array($payload['resource'] ?? null), 400, 'Invalid local payment notification.');
        $resource = $payload['resource'];

        return ['notification_id' => (string) ($payload['id'] ?? ''), 'event_type' => (string) ($payload['event_type'] ?? ''), 'payment_no' => (string) ($resource['out_trade_no'] ?? ''), 'provider_trade_no' => $resource['transaction_id'] ?? null, 'trade_state' => (string) ($resource['trade_state'] ?? ''), 'cash_amount' => (int) ($resource['amount']['total'] ?? 0), 'success_time' => $resource['success_time'] ?? null, 'raw_resource' => $resource];
    }

    public function query(Payment $payment): array
    {
        return ['notification_id' => 'local-query-'.$payment->payment_no, 'event_type' => 'TRANSACTION.QUERY', 'payment_no' => $payment->payment_no, 'provider_trade_no' => null, 'trade_state' => 'NOTPAY', 'cash_amount' => $payment->cash_amount, 'success_time' => null, 'raw_resource' => ['local' => true]];
    }

    public function close(Payment $payment): void
    {
        abort_unless(app()->environment(['local', 'testing']), 503);
    }
}
