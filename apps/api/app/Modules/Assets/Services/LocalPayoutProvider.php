<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Contracts\PayoutProvider;
use App\Modules\Assets\Models\WalletWithdrawal;

class LocalPayoutProvider implements PayoutProvider
{
    public function submit(WalletWithdrawal $withdrawal): array
    {
        $this->ensureAllowed();

        return $this->result($withdrawal, 'PROCESSING', 'local-'.$withdrawal->withdrawal_no, 'submit');
    }

    public function query(WalletWithdrawal $withdrawal): array
    {
        $this->ensureAllowed();

        return $this->result($withdrawal, 'PROCESSING', $withdrawal->channel_reference, 'query');
    }

    public function parseNotification(array $headers, string $body): array
    {
        $this->ensureAllowed();
        abort_unless(hash_equals('local-payout-signature', (string) ($headers['signature'] ?? '')), 401, 'Invalid local payout signature.');
        $payload = json_decode($body, true);
        abort_unless(is_array($payload), 400, 'Invalid local payout notification.');

        return [
            'event_id' => (string) ($payload['id'] ?? ''),
            'event_type' => (string) ($payload['event_type'] ?? 'PAYOUT.NOTIFY'),
            'withdrawal_no' => (string) ($payload['withdrawal_no'] ?? ''),
            'provider_reference' => $payload['provider_reference'] ?? null,
            'state' => strtoupper((string) ($payload['state'] ?? '')),
            'amount_cents' => (int) ($payload['amount_cents'] ?? 0),
            'occurred_at' => $payload['occurred_at'] ?? null,
            'raw_resource' => $payload,
        ];
    }

    public function name(): string
    {
        return 'local';
    }

    private function ensureAllowed(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 503, 'Local payout provider is disabled.');
    }

    /** @return array<string,mixed> */
    private function result(WalletWithdrawal $withdrawal, string $state, ?string $reference, string $source): array
    {
        return [
            'event_id' => "local-{$source}-{$withdrawal->withdrawal_no}-{$state}",
            'event_type' => 'PAYOUT.'.strtoupper($source),
            'withdrawal_no' => $withdrawal->withdrawal_no,
            'provider_reference' => $reference,
            'state' => $state,
            'amount_cents' => $withdrawal->received_amount_cents,
            'occurred_at' => null,
            'raw_resource' => ['local' => true, 'state' => $state],
        ];
    }
}
