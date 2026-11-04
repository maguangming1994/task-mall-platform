<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\PhoneVerificationRateLimitedException;
use App\Modules\Operations\Services\ReleaseControlService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class PhoneVerificationService
{
    public function __construct(
        private readonly AliyunSmsSender $aliyunSmsSender,
        private readonly SmsProviderSettingsService $settings,
        private readonly ReleaseControlService $releases,
    ) {}

    public function issue(string $phone, string $purpose): void
    {
        $lock = Cache::lock($this->issueLockKey($phone, $purpose), 10);
        if (! $lock->get()) {
            throw new PhoneVerificationRateLimitedException(5);
        }

        try {
            $configuration = $this->settings->effective();
            $this->ensureCanIssue($phone, $purpose, $configuration);

            $code = $this->verificationCode();
            $ttl = max(60, (int) ($configuration['ttl_seconds'] ?? 300));
            $driver = (string) ($configuration['driver'] ?? 'log');
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
            } elseif ($driver === 'aliyun') {
                $this->aliyunSmsSender->send($phone, $purpose, $code);
            } elseif ($driver === 'webhook') {
                $this->sendWebhook($phone, $purpose, $code, $ttl, $configuration);
            } else {
                throw new \RuntimeException('The configured phone verification provider is not available.');
            }

            Cache::put($this->cacheKey($phone, $purpose), [
                'code_hash' => hash_hmac('sha256', $code, $this->secret()),
                'attempts_remaining' => max(1, (int) ($configuration['max_attempts'] ?? 5)),
                'expires_at' => now()->addSeconds($ttl)->timestamp,
            ], $ttl);
            $this->recordIssue($phone, $purpose, $configuration);
        } finally {
            $lock->release();
        }
    }

    public function verify(string $phone, string $purpose, string $code): bool
    {
        if ($purpose === 'login' && $this->matchesReviewDemoCredential($phone, $code)) {
            return true;
        }

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

    /**
     * 审核演示登录：仅审核期开放，审核结束后自动失效，避免长期保留固定验证码后门。
     */
    private function matchesReviewDemoCredential(string $phone, string $code): bool
    {
        $demoPhone = (string) config('auth_tokens.review_demo.phone', '');
        $demoCode = (string) config('auth_tokens.review_demo.code', '');

        if ($demoPhone === '' || $demoCode === '' || ! hash_equals($demoPhone, $phone) || ! hash_equals($demoCode, $code)) {
            return false;
        }

        return $this->releases->enabled('review_in_progress', 'review-demo', 'wechat-mini');
    }

    private function cacheKey(string $phone, string $purpose): string
    {
        return 'phone-verification:'.$purpose.':'.$this->phoneHash($phone);
    }

    private function issueLockKey(string $phone, string $purpose): string
    {
        return 'phone-verification:issue-lock:'.$purpose.':'.$this->phoneHash($phone);
    }

    private function cooldownKey(string $phone, string $purpose): string
    {
        return 'phone-verification:cooldown:'.$purpose.':'.$this->phoneHash($phone);
    }

    private function hourlyRateKey(string $phone): string
    {
        return 'phone-verification:hour:'.$this->phoneHash($phone);
    }

    private function dailyRateKey(string $phone): string
    {
        return 'phone-verification:day:'.now()->format('Y-m-d').':'.$this->phoneHash($phone);
    }

    /** @param array<string,mixed> $configuration */
    private function ensureCanIssue(string $phone, string $purpose, array $configuration): void
    {
        $cooldown = max(0, (int) ($configuration['resend_interval_seconds'] ?? 60));
        if ($cooldown > 0 && Cache::has($this->cooldownKey($phone, $purpose))) {
            throw new PhoneVerificationRateLimitedException($cooldown);
        }

        $hourlyLimit = max(1, (int) ($configuration['hourly_limit'] ?? 5));
        if (RateLimiter::tooManyAttempts($this->hourlyRateKey($phone), $hourlyLimit)) {
            throw new PhoneVerificationRateLimitedException(max(1, RateLimiter::availableIn($this->hourlyRateKey($phone))));
        }

        $dailyLimit = max(1, (int) ($configuration['daily_limit'] ?? 10));
        if (RateLimiter::tooManyAttempts($this->dailyRateKey($phone), $dailyLimit)) {
            throw new PhoneVerificationRateLimitedException(max(1, RateLimiter::availableIn($this->dailyRateKey($phone))));
        }
    }

    /** @param array<string,mixed> $configuration */
    private function recordIssue(string $phone, string $purpose, array $configuration): void
    {
        $cooldown = max(0, (int) ($configuration['resend_interval_seconds'] ?? 60));
        if ($cooldown > 0) {
            Cache::put($this->cooldownKey($phone, $purpose), true, $cooldown);
        }

        RateLimiter::hit($this->hourlyRateKey($phone), 3600);
        RateLimiter::hit($this->dailyRateKey($phone), 86400);
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

    /** @param array<string,mixed> $configuration */
    private function sendWebhook(string $phone, string $purpose, string $code, int $ttl, array $configuration): void
    {
        $webhook = (array) ($configuration['webhook'] ?? config('auth_tokens.phone_verification.webhook', []));
        $endpoint = (string) ($webhook['endpoint'] ?? '');
        $token = (string) ($webhook['token'] ?? '');
        if ($endpoint === '' || $token === '') {
            throw new \RuntimeException('The SMS webhook provider is incomplete.');
        }
        $response = Http::asJson()
            ->withToken($token)
            ->timeout((int) ($webhook['timeout_seconds'] ?? 5))
            ->post($endpoint, [
                'phone' => $phone,
                'purpose' => $purpose,
                'verification_code' => $code,
                'expires_in' => $ttl,
                'template_id' => $webhook['template_id'] ?? null,
                'sign_name' => $webhook['sign_name'] ?? null,
            ]);
        if (! $response->successful()) {
            throw new \RuntimeException('The SMS provider rejected the verification request.');
        }
    }
}
