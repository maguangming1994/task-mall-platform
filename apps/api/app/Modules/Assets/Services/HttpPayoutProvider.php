<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Contracts\PayoutProvider;
use App\Modules\Assets\Models\WalletWithdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class HttpPayoutProvider implements PayoutProvider
{
    public function submit(WalletWithdrawal $withdrawal): array
    {
        $payload = [
            'payout_no' => $withdrawal->withdrawal_no,
            'amount' => $withdrawal->received_amount_cents,
            'currency' => 'CNY',
            'channel' => $withdrawal->channel,
            'callback_url' => config('payout.callback_url'),
        ];

        return $this->request('POST', '/payouts', $payload, $withdrawal);
    }

    public function query(WalletWithdrawal $withdrawal): array
    {
        return $this->request('GET', '/payouts/'.rawurlencode($withdrawal->withdrawal_no), [], $withdrawal);
    }

    public function parseNotification(array $headers, string $body): array
    {
        $this->ensureConfigured();
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        $signed = $timestamp."\n".$body;
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && $signature !== '' && hash_equals(hash_hmac('sha256', $signed, (string) config('payout.secret')), $signature), 401, 'Invalid payout callback signature.');
        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 400, 'Invalid payout callback.');

        return $this->normalize($payload, null, 'PAYOUT.NOTIFY');
    }

    public function name(): string
    {
        return 'http';
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload, WalletWithdrawal $withdrawal): array
    {
        $this->ensureConfigured();
        $body = $payload === [] ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}\n{$method}\n{$path}\n{$body}", (string) config('payout.secret'));
        $url = rtrim((string) config('payout.endpoint'), '/').$path;

        try {
            $request = Http::acceptJson()->withHeaders([
                'X-Payout-Timestamp' => $timestamp,
                'X-Payout-Signature' => $signature,
                'Idempotency-Key' => $withdrawal->withdrawal_no,
                'User-Agent' => 'task-mall-api/1.0',
            ])->timeout((int) config('payout.timeout_seconds'));
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['payout' => '出款渠道连接失败，提现仍保持处理中，请稍后主动查询。']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['payout' => '出款渠道未接受请求，提现仍保持处理中，请核对渠道配置后重试。']);
        }

        return $this->normalize((array) $response->json(), $withdrawal, $method === 'GET' ? 'PAYOUT.QUERY' : 'PAYOUT.SUBMIT');
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalize(array $payload, ?WalletWithdrawal $withdrawal, string $eventType): array
    {
        $state = strtoupper((string) ($payload['state'] ?? $payload['status'] ?? ''));
        $withdrawalNo = (string) ($payload['withdrawal_no'] ?? $payload['payout_no'] ?? $withdrawal?->withdrawal_no ?? '');
        $reference = $payload['provider_reference'] ?? $payload['transaction_id'] ?? null;

        return [
            'event_id' => (string) ($payload['event_id'] ?? $payload['id'] ?? 'query-'.$withdrawalNo.'-'.$state.'-'.($reference ?: 'none')),
            'event_type' => (string) ($payload['event_type'] ?? $eventType),
            'withdrawal_no' => $withdrawalNo,
            'provider_reference' => $reference,
            'state' => $state,
            'amount_cents' => (int) ($payload['amount_cents'] ?? $payload['amount'] ?? $withdrawal?->received_amount_cents ?? 0),
            'occurred_at' => $payload['occurred_at'] ?? $payload['completed_at'] ?? null,
            'raw_resource' => $payload,
        ];
    }

    private function ensureConfigured(): void
    {
        foreach (['endpoint', 'secret', 'callback_url'] as $key) {
            if (! filled(config('payout.'.$key))) {
                throw ValidationException::withMessages(['payout' => '生产出款渠道参数尚未配置完整，已阻止出款，未伪造成功结果。']);
            }
        }
    }
}
