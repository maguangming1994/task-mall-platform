<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Refund\Models\Refund;

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

    public function refund(Payment $payment, Refund $refund): array
    {
        abort_unless(app()->environment(['local', 'testing']), 503, 'Local refund gateway is disabled.');

        return $this->refundResult($refund, 'PENDING', 'local-'.$refund->refund_no, 'submit');
    }

    public function queryRefund(Refund $refund): array
    {
        abort_unless(app()->environment(['local', 'testing']), 503, 'Local refund gateway is disabled.');

        return $this->refundResult($refund, 'PROCESSING', null, 'query');
    }

    public function parseRefundNotification(array $headers, string $body): array
    {
        abort_unless(app()->environment(['local', 'testing']) && hash_equals('local-test-signature', (string) ($headers['signature'] ?? '')), 401, 'Invalid local refund signature.');
        $payload = json_decode($body, true);
        abort_unless(is_array($payload) && is_array($payload['resource'] ?? null), 400, 'Invalid local refund notification.');
        $resource = $payload['resource'];

        return [
            'notification_id' => (string) ($payload['id'] ?? ''),
            'event_type' => (string) ($payload['event_type'] ?? 'REFUND.NOTIFY'),
            'refund_no' => (string) ($resource['out_refund_no'] ?? ''),
            'provider_refund_no' => $resource['refund_id'] ?? null,
            'refund_state' => strtoupper((string) ($resource['refund_status'] ?? '')),
            'refund_amount' => (int) ($resource['amount']['refund'] ?? 0),
            'total_amount' => (int) ($resource['amount']['total'] ?? 0),
            'success_time' => $resource['success_time'] ?? null,
            'raw_resource' => $resource,
        ];
    }

    /** @return array<string,mixed> */
    private function refundResult(Refund $refund, string $state, ?string $reference, string $source): array
    {
        return [
            'notification_id' => "local-refund-{$source}-{$refund->refund_no}-{$state}",
            'event_type' => 'REFUND.'.strtoupper($source),
            'refund_no' => $refund->refund_no,
            'provider_refund_no' => $reference,
            'refund_state' => $state,
            'refund_amount' => $refund->refund_external_amount,
            'total_amount' => 0,
            'success_time' => null,
            'raw_resource' => ['local' => true, 'state' => $state],
        ];
    }
}
