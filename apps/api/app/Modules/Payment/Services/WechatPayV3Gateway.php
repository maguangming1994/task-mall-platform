<?php

namespace App\Modules\Payment\Services;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Payment\Contracts\WechatPaymentGateway;
use App\Modules\Payment\Models\Payment;
use App\Modules\Refund\Models\Refund;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class WechatPayV3Gateway implements WechatPaymentGateway
{
    public function prepare(Payment $payment, User $user): array
    {
        $this->ensureConfigured();
        $isH5 = $payment->scene === 'h5-browser';
        $path = $isH5 ? '/v3/pay/transactions/h5' : '/v3/pay/transactions/jsapi';
        $appId = $payment->scene === 'wechat-mini' ? config('wechat_payment.mini_app_id') : config('wechat_payment.official_account_app_id');
        $body = [
            'appid' => $appId, 'mchid' => config('wechat_payment.merchant_id'),
            'description' => $payment->order_id ? '任务商城订单 '.$payment->order->order_no : '任务商城余额充值 '.$payment->walletRecharge->recharge_no,
            'out_trade_no' => $payment->payment_no, 'notify_url' => config('wechat_payment.notify_url'),
            'amount' => ['total' => $payment->cash_amount, 'currency' => 'CNY'],
        ];
        if ($isH5) {
            $body['scene_info'] = ['payer_client_ip' => request()->ip() ?: '127.0.0.1', 'h5_info' => ['type' => 'Wap']];
        } else {
            $provider = $payment->scene === 'wechat-mini' ? 'wechat_mini' : 'wechat_oa';
            $identity = UserIdentity::query()->where('user_id', $user->id)->where('provider', $provider)->first();
            if (! $identity) {
                throw ValidationException::withMessages(['payment' => '当前账号缺少对应微信身份，请先使用微信登录。']);
            }
            $body['payer'] = ['openid' => $identity->provider_user_value];
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => $this->authorization('POST', $path, $json), 'User-Agent' => 'task-mall-api/1.0'])
                ->withBody($json, 'application/json')->timeout((int) config('wechat_payment.timeout_seconds'))->post('https://api.mch.weixin.qq.com'.$path);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payment' => '微信支付连接失败，请稍后重试。']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['payment' => '微信支付下单失败，请稍后重试。']);
        }
        $result = $response->json();
        if ($isH5) {
            return ['kind' => 'mweb', 'mweb_url' => $result['h5_url'] ?? null, 'sandbox' => false];
        }

        return $this->clientPayload((string) $appId, (string) ($result['prepay_id'] ?? ''), $payment->scene);
    }

    public function parseNotification(array $headers, string $body): array
    {
        $this->ensureConfigured();
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = base64_decode((string) ($headers['signature'] ?? ''), true);
        $certificatePath = (string) config('wechat_payment.platform_certificate_path');
        $certificate = is_file($certificatePath) ? file_get_contents($certificatePath) : false;
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || ! $signature || ! $certificate || openssl_verify("{$timestamp}\n{$nonce}\n{$body}\n", $signature, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            abort(401, 'Invalid WeChat Pay signature.');
        }
        $envelope = json_decode($body, true);
        $resource = $envelope['resource'] ?? null;
        abort_unless(is_array($envelope) && is_array($resource), 400, 'Invalid WeChat Pay notification.');
        $cipher = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
        abort_unless($cipher !== false && strlen($cipher) > 16, 400, 'Invalid WeChat Pay ciphertext.');
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', (string) config('wechat_payment.api_v3_key'), OPENSSL_RAW_DATA, (string) ($resource['nonce'] ?? ''), substr($cipher, -16), (string) ($resource['associated_data'] ?? ''));
        $trade = $plain === false ? null : json_decode($plain, true);
        abort_unless(is_array($trade), 400, 'WeChat Pay notification decryption failed.');

        return ['notification_id' => (string) ($envelope['id'] ?? ''), 'event_type' => (string) ($envelope['event_type'] ?? ''), 'payment_no' => (string) ($trade['out_trade_no'] ?? ''), 'provider_trade_no' => $trade['transaction_id'] ?? null, 'trade_state' => (string) ($trade['trade_state'] ?? ''), 'cash_amount' => (int) ($trade['amount']['total'] ?? 0), 'success_time' => $trade['success_time'] ?? null, 'raw_resource' => $trade];
    }

    public function query(Payment $payment): array
    {
        $this->ensureConfigured();
        $path = '/v3/pay/transactions/out-trade-no/'.$payment->payment_no.'?mchid='.urlencode((string) config('wechat_payment.merchant_id'));
        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => $this->authorization('GET', $path, ''), 'User-Agent' => 'task-mall-api/1.0'])->timeout((int) config('wechat_payment.timeout_seconds'))->get('https://api.mch.weixin.qq.com'.$path);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payment' => '微信支付查询连接失败。']);
        }
        abort_unless($response->successful(), 503, 'WeChat Pay query failed.');
        $trade = $response->json();

        $state = (string) ($trade['trade_state'] ?? '');
        $resultKey = (string) ($trade['transaction_id'] ?? now()->format('YmdHi'));

        return ['notification_id' => 'query-'.$payment->payment_no.'-'.$state.'-'.$resultKey, 'event_type' => 'TRANSACTION.QUERY', 'payment_no' => $payment->payment_no, 'provider_trade_no' => $trade['transaction_id'] ?? null, 'trade_state' => $state, 'cash_amount' => (int) ($trade['amount']['total'] ?? $payment->cash_amount), 'success_time' => $trade['success_time'] ?? null, 'raw_resource' => $trade];
    }

    public function close(Payment $payment): void
    {
        $this->ensureConfigured();
        $path = '/v3/pay/transactions/out-trade-no/'.$payment->payment_no.'/close';
        $json = json_encode(['mchid' => config('wechat_payment.merchant_id')], JSON_UNESCAPED_SLASHES);
        try {
            $response = Http::withHeaders(['Authorization' => $this->authorization('POST', $path, $json), 'User-Agent' => 'task-mall-api/1.0'])->withBody($json, 'application/json')->timeout((int) config('wechat_payment.timeout_seconds'))->post('https://api.mch.weixin.qq.com'.$path);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payment' => '微信支付关单连接失败。']);
        }
        abort_unless($response->successful() || $response->status() === 204, 503, 'WeChat Pay close failed.');
    }

    public function refund(Payment $payment, Refund $refund): array
    {
        $this->ensureRefundConfigured();
        $path = '/v3/refund/domestic/refunds';
        $payload = [
            'out_refund_no' => $refund->refund_no,
            'reason' => mb_substr('订单退款 '.$refund->refund_no, 0, 80),
            'notify_url' => config('wechat_payment.refund_notify_url'),
            'amount' => [
                'refund' => $refund->refund_external_amount,
                'total' => $payment->cash_amount,
                'currency' => 'CNY',
            ],
        ];
        if (filled($payment->provider_trade_no)) {
            $payload['transaction_id'] = $payment->provider_trade_no;
        } else {
            $payload['out_trade_no'] = $payment->payment_no;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = Http::acceptJson()->withHeaders([
                'Authorization' => $this->authorization('POST', $path, $json),
                'User-Agent' => 'task-mall-api/1.0',
            ])->withBody($json, 'application/json')->timeout((int) config('wechat_payment.timeout_seconds'))
                ->post('https://api.mch.weixin.qq.com'.$path);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['refund' => '微信退款渠道连接失败，退款仍保持处理中，请稍后主动查询。']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['refund' => '微信退款请求未被渠道接受，退款仍保持处理中，请核对商户证书和退款配置。']);
        }

        return $this->normalizeRefund((array) $response->json(), 'REFUND.SUBMIT');
    }

    public function queryRefund(Refund $refund): array
    {
        $this->ensureRefundConfigured();
        $path = '/v3/refund/domestic/refunds/out-refund-no/'.rawurlencode($refund->refund_no);
        try {
            $response = Http::acceptJson()->withHeaders([
                'Authorization' => $this->authorization('GET', $path, ''),
                'User-Agent' => 'task-mall-api/1.0',
            ])->timeout((int) config('wechat_payment.timeout_seconds'))->get('https://api.mch.weixin.qq.com'.$path);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['refund' => '微信退款查询连接失败，未改变本地退款状态。']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['refund' => '微信退款主动查询失败，未改变本地退款状态。']);
        }

        return $this->normalizeRefund((array) $response->json(), 'REFUND.QUERY');
    }

    public function parseRefundNotification(array $headers, string $body): array
    {
        $this->ensureRefundConfigured();
        [$envelope, $resource] = $this->decryptNotification($headers, $body);

        return [
            'notification_id' => (string) ($envelope['id'] ?? ''),
            'event_type' => (string) ($envelope['event_type'] ?? ''),
            'refund_no' => (string) ($resource['out_refund_no'] ?? ''),
            'provider_refund_no' => $resource['refund_id'] ?? null,
            'refund_state' => strtoupper((string) ($resource['refund_status'] ?? '')),
            'refund_amount' => (int) ($resource['amount']['refund'] ?? 0),
            'total_amount' => (int) ($resource['amount']['total'] ?? 0),
            'success_time' => $resource['success_time'] ?? null,
            'raw_resource' => $resource,
        ];
    }

    /** @param array<string,mixed> $resource
     * @return array<string,mixed>
     */
    private function normalizeRefund(array $resource, string $eventType): array
    {
        $state = strtoupper((string) ($resource['status'] ?? $resource['refund_status'] ?? ''));
        $refundNo = (string) ($resource['out_refund_no'] ?? '');
        $providerNo = $resource['refund_id'] ?? null;

        return [
            'notification_id' => 'query-'.$refundNo.'-'.$state.'-'.($providerNo ?: 'none'),
            'event_type' => $eventType,
            'refund_no' => $refundNo,
            'provider_refund_no' => $providerNo,
            'refund_state' => $state,
            'refund_amount' => (int) ($resource['amount']['refund'] ?? 0),
            'total_amount' => (int) ($resource['amount']['total'] ?? 0),
            'success_time' => $resource['success_time'] ?? null,
            'raw_resource' => $resource,
        ];
    }

    /** @param array<string,string|null> $headers
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function decryptNotification(array $headers, string $body): array
    {
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = base64_decode((string) ($headers['signature'] ?? ''), true);
        $certificatePath = (string) config('wechat_payment.platform_certificate_path');
        $certificate = is_file($certificatePath) ? file_get_contents($certificatePath) : false;
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || ! $signature || ! $certificate || openssl_verify("{$timestamp}\n{$nonce}\n{$body}\n", $signature, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            abort(401, 'Invalid WeChat Pay signature.');
        }
        $envelope = json_decode($body, true);
        $encrypted = $envelope['resource'] ?? null;
        abort_unless(is_array($envelope) && is_array($encrypted), 400, 'Invalid WeChat Pay notification.');
        $cipher = base64_decode((string) ($encrypted['ciphertext'] ?? ''), true);
        abort_unless($cipher !== false && strlen($cipher) > 16, 400, 'Invalid WeChat Pay ciphertext.');
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', (string) config('wechat_payment.api_v3_key'), OPENSSL_RAW_DATA, (string) ($encrypted['nonce'] ?? ''), substr($cipher, -16), (string) ($encrypted['associated_data'] ?? ''));
        $resource = $plain === false ? null : json_decode($plain, true);
        abort_unless(is_array($resource), 400, 'WeChat Pay notification decryption failed.');

        return [$envelope, $resource];
    }

    private function clientPayload(string $appId, string $prepayId, string $scene): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $package = 'prepay_id='.$prepayId;

        return ['kind' => $scene === 'wechat-mini' ? 'mini_program' : 'jsapi', 'appId' => $appId, 'timeStamp' => $timestamp, 'nonceStr' => $nonce, 'package' => $package, 'signType' => 'RSA', 'paySign' => $this->sign("{$appId}\n{$timestamp}\n{$nonce}\n{$package}\n"), 'sandbox' => false];
    }

    private function authorization(string $method, string $path, string $body): string
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $signature = $this->sign("{$method}\n{$path}\n{$timestamp}\n{$nonce}\n{$body}\n");

        return sprintf('WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"', config('wechat_payment.merchant_id'), $nonce, $timestamp, config('wechat_payment.merchant_certificate_serial'), $signature);
    }

    private function sign(string $message): string
    {
        $path = (string) config('wechat_payment.merchant_private_key_path');
        $key = is_file($path) ? file_get_contents($path) : false;
        if (! $key || ! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw ValidationException::withMessages(['payment' => '微信支付商户私钥不可用。']);
        }

        return base64_encode($signature);
    }

    private function ensureConfigured(): void
    {
        foreach (['merchant_id', 'merchant_certificate_serial', 'merchant_private_key_path', 'api_v3_key', 'notify_url'] as $key) {
            if (! filled(config('wechat_payment.'.$key))) {
                throw ValidationException::withMessages(['payment' => '微信支付生产参数尚未配置完整。']);
            }
        }
    }

    private function ensureRefundConfigured(): void
    {
        foreach (['merchant_id', 'merchant_certificate_serial', 'merchant_private_key_path', 'api_v3_key', 'platform_certificate_path', 'refund_notify_url'] as $key) {
            $value = config('wechat_payment.'.$key);
            if (! filled($value)) {
                throw ValidationException::withMessages(['refund' => '微信退款生产参数尚未配置完整，已阻止退款，未伪造成功结果。']);
            }
        }
        if (! is_file((string) config('wechat_payment.merchant_private_key_path')) || ! is_file((string) config('wechat_payment.platform_certificate_path'))) {
            throw ValidationException::withMessages(['refund' => '微信退款商户私钥或平台证书不可用，已阻止退款，未伪造成功结果。']);
        }
        if (strlen((string) config('wechat_payment.api_v3_key')) !== 32) {
            throw ValidationException::withMessages(['refund' => '微信支付 API V3 密钥必须为 32 字节，已阻止退款。']);
        }
    }
}
