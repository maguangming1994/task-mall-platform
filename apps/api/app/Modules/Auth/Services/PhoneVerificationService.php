<?php

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhoneVerificationService
{
    public function issue(string $phone, string $purpose): void
    {
        $code = $this->verificationCode();
        $ttl = (int) config('auth_tokens.phone_verification.ttl_seconds');
        $driver = (string) config('auth_tokens.phone_verification.driver');
        if ($driver === 'log') {
            if (! app()->environment(['local', 'testing'])) {
                throw new \RuntimeException('The local phone verification provider is disabled outside local and testing environments.');
            }
            Log::info('Phone verification code issued by local log adapter.', [
                'phone_hash' => $this->phoneHash($phone),
                'purpose' => $purpose,
                'code' => $code,
                'expires_in' => $ttl,
            ]);
        } elseif ($driver === 'webhook') {
            $this->sendWebhook($phone, $purpose, $code, $ttl);
        } else {
            throw new \RuntimeException('The configured phone verification provider is not available.');
        }

        Cache::put($this->cacheKey($phone, $purpose), [
            'code_hash' => hash_hmac('sha256', $code, $this->secret()),
            'attempts_remaining' => 5,
            'expires_at' => now()->addSeconds($ttl)->timestamp,
        ], $ttl);

    }

    public function verify(string $phone, string $purpose, string $code): bool
    {
        $key = $this->cacheKey($phone, $purpose);
        $record = Cache::get($key);

        if (! is_array($record)
            || ! isset($record['code_hash'], $record['attempts_remaining'], $record['expires_at'])
            || (int) $record['expires_at'] <= now()->timestamp) {
            Cache::forget($key);

            return false;
        }

        $valid = hash_equals(
            (string) $record['code_hash'],
            hash_hmac('sha256', $code, $this->secret()),
        );

        if ($valid) {
            Cache::forget($key);

            return true;
        }

        $record['attempts_remaining'] = (int) $record['attempts_remaining'] - 1;
        if ($record['attempts_remaining'] <= 0) {
            Cache::forget($key);
        } else {
            Cache::put($key, $record, max(1, (int) $record['expires_at'] - now()->timestamp));
        }

        return false;
    }

    public function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', $phone, $this->secret());
    }

    private function cacheKey(string $phone, string $purpose): string
    {
        return 'phone-verification:'.$purpose.':'.$this->phoneHash($phone);
    }

    private function verificationCode(): string
    {
        if (app()->environment(['local', 'testing'])) {
            return (string) config('auth_tokens.phone_verification.development_code');
        }

        return (string) random_int(100000, 999999);
    }

    private function secret(): string
    {
        return hash('sha256', (string) config('auth_tokens.identity_hash_secret'));
    }

    private function sendWebhook(string $phone, string $purpose, string $code, int $ttl): void
    {
        $endpoint = (string) config('auth_tokens.phone_verification.webhook.endpoint');
        $token = (string) config('auth_tokens.phone_verification.webhook.token');
        if ($endpoint === '' || $token === '') {
            throw new \RuntimeException('The SMS webhook provider is incomplete.');
        }
        $response = Http::asJson()
            ->withToken($token)
            ->timeout((int) config('auth_tokens.phone_verification.webhook.timeout_seconds'))
            ->post($endpoint, [
                'phone' => $phone,
                'purpose' => $purpose,
                'verification_code' => $code,
                'expires_in' => $ttl,
                'template_id' => config('auth_tokens.phone_verification.webhook.template_id'),
                'sign_name' => config('auth_tokens.phone_verification.webhook.sign_name'),
            ]);
        if (! $response->successful()) {
            throw new \RuntimeException('The SMS provider rejected the verification request.');
        }
    }
}
