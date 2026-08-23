<?php

namespace App\Modules\Assets\Services;

use App\Common\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\WalletRecharge;
use App\Modules\Assets\Models\WalletRechargeCard;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletRechargeService
{
    public function __construct(private readonly AssetLedgerService $ledger) {}

    public function create(User $user, array $data, string $idempotencyKey): WalletRecharge
    {
        $method = strtolower(trim((string) ($data['method'] ?? '')));
        $hash = $this->idempotencyKeyHash($user->id, 'wallet-recharge.create', $idempotencyKey);
        $legacyHash = hash('sha256', $idempotencyKey);
        $payloadHash = $this->requestFingerprint($method, $data);

        return match ($method) {
            'card_code' => $this->redeemCard($user, (string) ($data['card_code'] ?? ''), $hash, $legacyHash, $payloadHash),
            'online', 'offline' => DB::transaction(function () use ($user, $method, $data, $hash, $legacyHash, $payloadHash): WalletRecharge {
                User::query()->lockForUpdate()->findOrFail($user->id);
                $existing = $this->existingRecharge($user, $hash, $legacyHash);
                if ($existing !== null) {
                    $this->assertRechargeFingerprint($existing, $payloadHash);

                    return $existing->fresh();
                }

                return $method === 'online'
                    ? WalletRecharge::query()->create($this->base($user, 'online', (int) $data['amount_cents'], 'pending_payment', $hash, $payloadHash) + ['expires_at' => now()->addMinutes(30)])
                    : $this->createOffline($user, $data, $hash, $payloadHash);
            }),
            default => throw ValidationException::withMessages(['method' => '不支持的充值方式。']),
        };
    }

    public function settleOnline(WalletRecharge $recharge): WalletRecharge
    {
        return DB::transaction(function () use ($recharge): WalletRecharge {
            $locked = WalletRecharge::query()->lockForUpdate()->findOrFail($recharge->id);
            if ($locked->status === 'succeeded') {
                return $locked;
            }
            if ($locked->status !== 'pending_payment') {
                throw ValidationException::withMessages(['recharge' => '充值单当前不能入账。']);
            }
            $user = User::query()->findOrFail($locked->user_id);
            $this->ledger->changeWallet($user, $locked->amount_cents, '线上充值到账', 'wallet-recharge-online:'.$locked->public_id, null, 'recharge_online');
            $locked->update(['status' => 'succeeded', 'succeeded_at' => now()]);

            return $locked->fresh();
        });
    }

    public function closeOnline(WalletRecharge $recharge, string $status = 'expired'): WalletRecharge
    {
        if ($recharge->status === 'pending_payment') {
            $recharge->update(['status' => $status]);
        }

        return $recharge->fresh();
    }

    public function review(AdminUser $admin, WalletRecharge $recharge, string $decision, string $note): WalletRecharge
    {
        return DB::transaction(function () use ($admin, $recharge, $decision, $note): WalletRecharge {
            $locked = WalletRecharge::query()->lockForUpdate()->findOrFail($recharge->id);
            if ($locked->method !== 'offline' || $locked->status !== 'pending_review') {
                throw ValidationException::withMessages(['recharge' => '该线下充值单已处理或当前无需审核。']);
            }
            $updates = ['status' => $decision === 'approve' ? 'succeeded' : 'rejected', 'reviewed_by_admin_user_id' => $admin->id, 'review_note' => $note, 'reviewed_at' => now()];
            if ($decision === 'approve') {
                $user = User::query()->findOrFail($locked->user_id);
                $this->ledger->changeWallet($user, $locked->amount_cents, '线下充值审核通过：'.$note, 'wallet-recharge-offline:'.$locked->public_id, $admin, 'recharge_offline');
                $updates['succeeded_at'] = now();
            }
            $locked->update($updates);

            return $locked->fresh();
        });
    }

    public function createCards(AdminUser $admin, int $amountCents, int $quantity, ?string $expiresAt): array
    {
        $plainCodes = [];
        $cards = DB::transaction(function () use ($admin, $amountCents, $quantity, $expiresAt, &$plainCodes): array {
            $created = [];
            for ($i = 0; $i < $quantity; $i++) {
                $raw = strtoupper(Str::random(20));
                $plain = implode('-', str_split($raw, 5));
                $plainCodes[] = $plain;
                $created[] = WalletRechargeCard::query()->create(['code_hash' => hash('sha256', $raw), 'code_mask' => '*****-*****-*****-'.substr($raw, -5), 'amount_cents' => $amountCents, 'status' => 'active', 'expires_at' => $expiresAt, 'created_by_admin_user_id' => $admin->id]);
            }

            return $created;
        });

        return ['cards' => array_map(fn (WalletRechargeCard $card, int $i) => $this->presentCard($card) + ['code' => $plainCodes[$i]], $cards, array_keys($cards)), 'warning' => '卡密明文仅本次返回，请立即安全保存。'];
    }

    public function present(WalletRecharge $recharge): array
    {
        return ['id' => $recharge->public_id, 'recharge_no' => $recharge->recharge_no, 'user_id' => $recharge->user?->public_id ?? $recharge->user()->value('public_id'), 'method' => $recharge->method, 'amount_cents' => $recharge->amount_cents, 'status' => $recharge->status, 'payer_name' => $recharge->payer_name, 'offline_reference' => $recharge->offline_reference, 'note' => $recharge->note, 'offline_proof_file_ids' => $recharge->offline_proof_file_ids ?? [], 'review_note' => $recharge->review_note, 'expires_at' => $recharge->expires_at?->toIso8601String(), 'reviewed_at' => $recharge->reviewed_at?->toIso8601String(), 'succeeded_at' => $recharge->succeeded_at?->toIso8601String(), 'created_at' => $recharge->created_at->toIso8601String()];
    }

    public function presentCard(WalletRechargeCard $card): array
    {
        return ['id' => $card->public_id, 'code_mask' => $card->code_mask, 'amount_cents' => $card->amount_cents, 'status' => $card->status, 'expires_at' => $card->expires_at?->toIso8601String(), 'redeemed_at' => $card->redeemed_at?->toIso8601String(), 'created_at' => $card->created_at->toIso8601String()];
    }

    private function redeemCard(User $user, string $code, string $hash, string $legacyHash, string $payloadHash): WalletRecharge
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        if (strlen($normalized) < 12) {
            throw ValidationException::withMessages(['card_code' => '请输入完整有效的充值卡密。']);
        }

        return DB::transaction(function () use ($user, $normalized, $hash, $legacyHash, $payloadHash): WalletRecharge {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = $this->existingRecharge($user, $hash, $legacyHash);
            if ($existing !== null) {
                $this->assertRechargeFingerprint($existing, $payloadHash);

                return $existing->fresh();
            }

            $card = WalletRechargeCard::query()->where('code_hash', hash('sha256', $normalized))->lockForUpdate()->first();
            if (! $card || $card->status !== 'active' || $card->expires_at?->isPast()) {
                throw ValidationException::withMessages(['card_code' => '卡密无效、已使用或已过期。']);
            }
            $recharge = WalletRecharge::query()->create($this->base($user, 'card_code', $card->amount_cents, 'succeeded', $hash, $payloadHash) + ['succeeded_at' => now()]);
            $this->ledger->changeWallet($user, $card->amount_cents, '充值卡密兑换到账', 'wallet-recharge-card:'.$card->public_id, null, 'recharge_card');
            $card->update(['status' => 'redeemed', 'redeemed_by_user_id' => $user->id, 'redeemed_recharge_id' => $recharge->id, 'redeemed_at' => now()]);

            return $recharge->fresh();
        });
    }

    private function createOffline(User $user, array $data, string $hash, string $payloadHash): WalletRecharge
    {
        $ids = array_values(array_unique($data['offline_proof_file_ids'] ?? []));
        $count = UploadedFile::query()->where('owner_user_id', $user->id)->where('purpose', 'offline_recharge')->where('status', 'clean')->whereIn('public_id', $ids)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['offline_proof_file_ids' => '充值凭证不存在、尚未通过安全扫描或不属于当前用户。']);
        }

        return WalletRecharge::query()->create($this->base($user, 'offline', (int) $data['amount_cents'], 'pending_review', $hash, $payloadHash) + ['payer_name' => $data['payer_name'], 'offline_reference' => $data['offline_reference'] ?? null, 'note' => $data['note'] ?? null, 'offline_proof_file_ids' => $ids]);
    }

    private function idempotencyKeyHash(int $actorId, string $operation, string $key): string
    {
        return hash('sha256', 'user:'.$actorId.':'.$operation.':'.trim($key));
    }

    private function existingRecharge(User $user, string $idempotencyHash, string $legacyHash): ?WalletRecharge
    {
        $existing = WalletRecharge::query()->where('idempotency_key_hash', $idempotencyHash)->lockForUpdate()->first();
        if ($existing !== null) {
            abort_unless($existing->user_id === $user->id, 404);

            return $existing;
        }

        if ($legacyHash !== $idempotencyHash) {
            $legacy = WalletRecharge::query()->where('idempotency_key_hash', $legacyHash)->lockForUpdate()->first();
            if ($legacy?->user_id === $user->id) {
                return $legacy;
            }
        }

        return null;
    }

    private function assertRechargeFingerprint(WalletRecharge $recharge, string $fingerprint): void
    {
        $stored = $recharge->request_fingerprint_hash;
        if (! is_string($stored) || $stored === '') {
            $stored = $this->legacyRechargeFingerprint($recharge);
        }
        if (is_string($stored) && $stored !== '' && ! hash_equals($stored, $fingerprint)) {
            throw new IdempotencyConflictException('相同幂等键已绑定不同的充值请求，请更换幂等键。');
        }
        if ($recharge->request_fingerprint_hash === null || $recharge->request_fingerprint_hash === '') {
            $recharge->update(['request_fingerprint_hash' => $fingerprint]);
        }
    }

    private function legacyRechargeFingerprint(WalletRecharge $recharge): ?string
    {
        $method = strtolower(trim((string) $recharge->method));
        $data = [
            'method' => $method,
            'amount_cents' => (int) $recharge->amount_cents,
            'payer_name' => $recharge->payer_name,
            'offline_reference' => $recharge->offline_reference,
            'note' => $recharge->note,
            'offline_proof_file_ids' => $recharge->offline_proof_file_ids ?? [],
        ];
        $cardIdentifier = null;
        if ($method === 'card_code') {
            $cardIdentifier = WalletRechargeCard::query()->where('redeemed_recharge_id', $recharge->id)->value('code_hash');
            if (! is_string($cardIdentifier) || $cardIdentifier === '') {
                return null;
            }
        }

        return $this->requestFingerprint($method, $data, $cardIdentifier);
    }

    private function requestFingerprint(string $method, array $data, ?string $cardIdentifier = null): string
    {
        $method = strtolower(trim($method));
        $proofIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($data['offline_proof_file_ids'] ?? []),
        ), static fn (string $value): bool => $value !== '')));
        sort($proofIds, SORT_STRING);
        $normalizedCardIdentifier = null;
        if ($method === 'card_code') {
            $normalizedCardIdentifier = $cardIdentifier ?? hash(
                'sha256',
                strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($data['card_code'] ?? '')) ?? ''),
            );
        }
        $payload = [
            'operation' => 'wallet-recharge.create',
            'method' => $method,
            'amount_cents' => $method === 'card_code' ? null : (int) ($data['amount_cents'] ?? 0),
            'card_identifier' => $normalizedCardIdentifier,
            'payer_name' => $method === 'offline' ? $this->nullableString($data['payer_name'] ?? null) : null,
            'offline_reference' => $method === 'offline' ? $this->nullableString($data['offline_reference'] ?? null) : null,
            'note' => $method === 'offline' ? $this->nullableString($data['note'] ?? null) : null,
            'offline_proof_file_ids' => $method === 'offline' ? $proofIds : [],
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function base(User $user, string $method, int $amount, string $status, string $hash, string $payloadHash): array
    {
        return ['recharge_no' => 'R'.now()->format('YmdHis').strtoupper(Str::random(10)), 'user_id' => $user->id, 'method' => $method, 'amount_cents' => $amount, 'status' => $status, 'idempotency_key_hash' => $hash, 'request_fingerprint_hash' => $payloadHash];
    }
}
