<?php

namespace App\Modules\Auth\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Exceptions\PhoneVerificationRateLimitedException;
use App\Modules\Auth\Models\SmsProviderCredential;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SmsProviderSettingsService
{
    public const SETTING_KEY = 'auth.phone_verification';

    private const CREDENTIAL_FIELDS = ['access_key_id', 'access_key_secret', 'security_token'];

    private const SETTING_FIELDS = [
        'driver',
        'ttl_seconds',
        'max_attempts',
        'resend_interval_seconds',
        'hourly_limit',
        'daily_limit',
        'aliyun',
    ];

    private const ALIYUN_FIELDS = [
        'region',
        'endpoint',
        'sign_name',
        'template_code',
        'verification_variables',
        'timeout_seconds',
    ];

    public function __construct(private readonly SystemSettingService $systemSettings) {}

    /**
     * Resolve non-secret phone verification settings. A published admin value
     * takes precedence over the environment; an unset setting falls back to
     * the existing config tree so current deployments remain compatible.
     *
     * @return array<string,mixed>
     */
    public function effective(): array
    {
        return $this->resolved()['configuration'];
    }

    /**
     * Resolve the three credentials for the SMS sender. Values saved by an
     * administrator override env values field-by-field; no caller should put
     * the returned array in an API response or audit payload.
     *
     * @return array{access_key_id:?string,access_key_secret:?string,security_token:?string}
     */
    public function aliyunCredentials(): array
    {
        $environment = (array) config('auth_tokens.phone_verification.aliyun', []);
        $record = SmsProviderCredential::query()->where('provider', 'aliyun')->first();

        $credentials = [];
        foreach (self::CREDENTIAL_FIELDS as $field) {
            $stored = null;
            if ($record !== null) {
                try {
                    $stored = $record->{$field};
                } catch (Throwable $exception) {
                    throw new RuntimeException('The stored Aliyun SMS credentials are unavailable.', 0, $exception);
                }
            }
            $credentials[$field] = filled($stored)
                ? (string) $stored
                : $this->nullableString($environment[$field] ?? null);
        }

        return $credentials;
    }

    /**
     * Return the administrative representation. Secret values are deliberately
     * reduced to a configured flag and a one-way-looking mask.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $resolved = $this->resolved();
        $configuration = $resolved['configuration'];
        $stored = SmsProviderCredential::query()->where('provider', 'aliyun')->first();
        $environment = (array) config('auth_tokens.phone_verification.aliyun', []);

        $credentialSnapshot = [];
        foreach (self::CREDENTIAL_FIELDS as $field) {
            $value = null;
            $source = 'none';
            if ($stored !== null) {
                try {
                    $value = $stored->{$field};
                } catch (Throwable $exception) {
                    throw new RuntimeException('The stored Aliyun SMS credentials are unavailable.', 0, $exception);
                }
                if (filled($value)) {
                    $source = 'backend';
                }
            }
            if (! filled($value) && filled($environment[$field] ?? null)) {
                $value = (string) $environment[$field];
                $source = 'env';
            }
            $credentialSnapshot[$field] = [
                'configured' => filled($value),
                'masked' => $this->mask($value),
            ];
        }

        $aliyun = (array) ($configuration['aliyun'] ?? []);
        $lastTest = $stored === null ? null : [
            'last_tested_at' => $stored->last_tested_at?->toIso8601String(),
            'last_test_status' => $stored->last_test_status,
            'last_test_message' => $stored->last_test_message,
        ];

        return [
            'driver' => (string) ($configuration['driver'] ?? 'log'),
            ...$credentialSnapshot,
            'region' => $this->nullableResponseString($aliyun['region'] ?? null),
            'endpoint' => $this->nullableResponseString($aliyun['endpoint'] ?? null),
            'sign_name' => $this->nullableResponseString($aliyun['sign_name'] ?? null),
            'template_code' => $this->nullableResponseString($aliyun['template_code'] ?? null),
            'verification_variables' => $this->verificationVariables($aliyun),
            'timeout_seconds' => (int) ($aliyun['timeout_seconds'] ?? 5),
            'ttl_seconds' => (int) ($configuration['ttl_seconds'] ?? 300),
            'resend_interval_seconds' => (int) ($configuration['resend_interval_seconds'] ?? 60),
            'hourly_limit' => (int) ($configuration['hourly_limit'] ?? 5),
            'daily_limit' => (int) ($configuration['daily_limit'] ?? 10),
            'max_attempts' => (int) ($configuration['max_attempts'] ?? 5),
            ...($lastTest ?? [
                'last_tested_at' => null,
                'last_test_status' => null,
                'last_test_message' => null,
            ]),
        ];
    }

    /**
     * Merge a partial administrative request over the current effective
     * settings. Credential fields are rejected here so they can never reach a
     * SystemSetting draft or version.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function buildSettings(array $input): array
    {
        $input = $input['settings'] ?? $input;
        if (! is_array($input) || array_is_list($input)) {
            throw ValidationException::withMessages(['settings' => '短信设置必须为对象。']);
        }

        foreach (self::CREDENTIAL_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                throw ValidationException::withMessages([
                    $field => '短信访问凭据必须通过凭据字段提交，不能写入系统设置。',
                ]);
            }
        }

        $current = $this->effective();
        foreach (self::SETTING_FIELDS as $field) {
            if ($field !== 'aliyun' && array_key_exists($field, $input)) {
                $current[$field] = $input[$field];
            }
        }
        $aliyunInput = $input['aliyun'] ?? [];
        if (! is_array($aliyunInput) || ($aliyunInput !== [] && array_is_list($aliyunInput))) {
            throw ValidationException::withMessages(['aliyun' => '阿里云短信设置必须为对象。']);
        }
        foreach (self::ALIYUN_FIELDS as $field) {
            if (array_key_exists($field, $input) && ! ($input[$field] === null && in_array($field, ['region', 'endpoint', 'sign_name', 'template_code'], true))) {
                $aliyunInput[$field] = $input[$field];
            }
        }
        // Accept the public flat contract as well as the internal nested form.
        $flatAliases = [
            'region' => 'region',
            'endpoint' => 'endpoint',
            'sign_name' => 'sign_name',
            'template_code' => 'template_code',
            'verification_variables' => 'verification_variables',
            'timeout_seconds' => 'timeout_seconds',
        ];
        foreach ($flatAliases as $field => $target) {
            if (array_key_exists($field, $input) && ! ($input[$field] === null && in_array($field, ['region', 'endpoint', 'sign_name', 'template_code'], true))) {
                $aliyunInput[$target] = $input[$field];
            }
        }
        if ($aliyunInput !== []) {
            $current['aliyun'] = array_replace((array) ($current['aliyun'] ?? []), $aliyunInput);
        }
        $allowedInput = array_merge(self::SETTING_FIELDS, array_keys($flatAliases));
        if (array_key_exists('aliyun', $input)) {
            $unknown = array_diff(array_keys((array) $input['aliyun']), self::ALIYUN_FIELDS);
            if ($unknown !== []) {
                throw ValidationException::withMessages(['aliyun' => '包含不支持的阿里云短信字段。']);
            }
        }

        $unknown = array_diff(array_keys($input), $allowedInput);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['settings' => '包含不支持的短信设置字段。']);
        }

        return $this->validateSettings($current);
    }

    /**
     * Persist non-secret settings through the existing draft/version workflow,
     * then persist credential fields in the encrypted credential table.
     *
     * @param  array<string,mixed>  $settings
     * @param  array<string,mixed>  $credentials
     * @return array<string,mixed>
     */
    public function update(array $settings, array $credentials, string $reason, AdminUser $actor): array
    {
        $settings = $this->validateSettings($settings);
        $credentials = Arr::only($credentials, self::CREDENTIAL_FIELDS);

        DB::transaction(function () use ($settings, $credentials, $reason, $actor): void {
            $this->systemSettings->saveDraft(self::SETTING_KEY, $settings, $reason, $actor);
            $this->systemSettings->publish(self::SETTING_KEY, $actor);
            $this->saveCredentials($credentials);
        });

        return $this->snapshot();
    }

    /**
     * @return array<string,mixed>
     */
    public function test(string $phone, AdminUser $admin, AliyunSmsSender $sender): array
    {
        $rateKey = 'sms-admin-test:'.hash_hmac(
            'sha256',
            $admin->id.'|'.$phone,
            hash('sha256', (string) config('auth_tokens.identity_hash_secret')),
        );
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw new PhoneVerificationRateLimitedException(max(1, RateLimiter::availableIn($rateKey)));
        }
        RateLimiter::hit($rateKey, 3600);

        $code = app()->environment(['local', 'testing'])
            ? (string) config('auth_tokens.phone_verification.development_code', '246810')
            : (string) random_int(100000, 999999);
        $record = SmsProviderCredential::query()->firstOrNew(['provider' => 'aliyun']);

        try {
            $delivery = $sender->send($phone, 'admin_test', $code);
            $message = '阿里云短信测试请求已提交。';
            $this->recordTest($record, 'success', $message);

            return [
                'accepted' => true,
                'request_id' => is_array($delivery) ? ($delivery['request_id'] ?? null) : null,
                'sent_at' => now()->toIso8601String(),
                'message' => $message,
            ];
        } catch (Throwable $exception) {
            report($exception);
            $message = '阿里云短信测试失败，请检查配置和服务商状态。';
            $this->recordTest($record, 'failed', $message);
            throw ValidationException::withMessages(['provider' => $message]);
        }
    }

    /** @return array{configuration:array<string,mixed>,source:string} */
    private function resolved(): array
    {
        $environment = $this->environmentConfiguration();
        $source = 'env';
        $stored = null;
        try {
            $reading = $this->systemSettings->read(self::SETTING_KEY);
            if ($reading['source'] === 'published' && is_array($reading['value'])) {
                $stored = $reading['value'];
                $source = 'backend';
            }
        } catch (Throwable) {
            // The setting registry is optional during an old deployment. The
            // established env configuration remains the safe compatibility path.
        }

        $configuration = $stored === null
            ? $environment
            : array_replace_recursive($environment, $stored);

        return ['configuration' => $configuration, 'source' => $source];
    }

    /** @return array<string,mixed> */
    private function environmentConfiguration(): array
    {
        $raw = (array) config('auth_tokens.phone_verification', []);
        $rawAliyun = (array) ($raw['aliyun'] ?? []);
        $variables = $rawAliyun['verification_variables'] ?? null;
        if (! is_array($variables) || ! array_is_list($variables) || $variables === []) {
            $variables = [$rawAliyun['code_variable'] ?? 'code'];
        }
        $timeoutMilliseconds = max(1000, (int) ($rawAliyun['timeout_milliseconds'] ?? 5000));

        return [
            ...$raw,
            'aliyun' => [
                'region' => (string) ($rawAliyun['region'] ?? $rawAliyun['region_id'] ?? 'cn-hangzhou'),
                'endpoint' => (string) ($rawAliyun['endpoint'] ?? 'dysmsapi.aliyuncs.com'),
                'sign_name' => (string) ($rawAliyun['sign_name'] ?? ''),
                'template_code' => (string) ($rawAliyun['template_code'] ?? ''),
                'verification_variables' => array_values(array_filter(
                    array_map(static fn (mixed $item): string => trim((string) $item), $variables),
                    static fn (string $item): bool => $item !== '',
                )),
                'timeout_seconds' => max(1, min(30, (int) ceil($timeoutMilliseconds / 1000))),
            ],
        ];
    }

    /** @param array<string,mixed> $value */
    private function validateSettings(array $value): array
    {
        $driver = (string) ($value['driver'] ?? '');
        if (! in_array($driver, ['log', 'aliyun', 'webhook'], true)) {
            throw ValidationException::withMessages(['driver' => '短信驱动只能为 log、aliyun 或 webhook。']);
        }

        $ranges = [
            'ttl_seconds' => [60, 3600],
            'max_attempts' => [1, 10],
            'resend_interval_seconds' => [0, 3600],
            'hourly_limit' => [1, 100],
            'daily_limit' => [1, 1000],
        ];
        foreach ($ranges as $field => [$min, $max]) {
            if (! is_int($value[$field] ?? null) || $value[$field] < $min || $value[$field] > $max) {
                throw ValidationException::withMessages([$field => "{$field} 必须为 {$min} 至 {$max} 的整数。"]);
            }
        }

        $aliyun = $value['aliyun'] ?? null;
        if (! is_array($aliyun) || array_is_list($aliyun)) {
            throw ValidationException::withMessages(['aliyun' => '阿里云短信设置必须为对象。']);
        }
        foreach (['region', 'endpoint', 'sign_name', 'template_code'] as $field) {
            if (! is_string($aliyun[$field] ?? null) || mb_strlen($aliyun[$field]) > 500) {
                throw ValidationException::withMessages(["aliyun.{$field}" => '字段必须为不超过 500 个字符的文字。']);
            }
        }
        if ($aliyun['region'] === '' || $aliyun['endpoint'] === '') {
            throw ValidationException::withMessages(['aliyun' => '阿里云区域和接口地址不能为空。']);
        }
        $endpointHost = preg_replace('#^https?://#i', '', $aliyun['endpoint']) ?: $aliyun['endpoint'];
        if (filter_var('https://'.$endpointHost, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['aliyun.endpoint' => '阿里云接口地址格式不正确。']);
        }
        if (! is_array($aliyun['verification_variables'] ?? null) || ! array_is_list($aliyun['verification_variables']) || $aliyun['verification_variables'] === []) {
            throw ValidationException::withMessages(['aliyun.verification_variables' => '至少需要一个短信模板变量。']);
        }
        foreach ($aliyun['verification_variables'] as $variable) {
            if (! is_string($variable) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $variable)) {
                throw ValidationException::withMessages(['aliyun.verification_variables' => '短信模板变量只能包含字母、数字和下划线。']);
            }
        }
        if (! is_int($aliyun['timeout_seconds'] ?? null) || $aliyun['timeout_seconds'] < 1 || $aliyun['timeout_seconds'] > 30) {
            throw ValidationException::withMessages(['aliyun.timeout_seconds' => '超时时间必须为 1 至 30 秒。']);
        }

        return [
            'driver' => $driver,
            'ttl_seconds' => $value['ttl_seconds'],
            'max_attempts' => $value['max_attempts'],
            'resend_interval_seconds' => $value['resend_interval_seconds'],
            'hourly_limit' => $value['hourly_limit'],
            'daily_limit' => $value['daily_limit'],
            'aliyun' => Arr::only($aliyun, self::ALIYUN_FIELDS),
        ];
    }

    /** @param array<string,mixed> $credentials */
    private function saveCredentials(array $credentials): void
    {
        if ($credentials === []) {
            return;
        }

        $record = SmsProviderCredential::query()->firstOrNew(['provider' => 'aliyun']);
        foreach (self::CREDENTIAL_FIELDS as $field) {
            if (! array_key_exists($field, $credentials)) {
                continue;
            }
            $value = $credentials[$field];
            $record->{$field} = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
        }
        $record->save();
    }

    private function recordTest(?SmsProviderCredential $record, string $status, string $message): void
    {
        if ($record === null) {
            return;
        }
        $record->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_message' => $message,
        ])->save();
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /** @param array<string,mixed> $aliyun */
    private function verificationVariables(array $aliyun): array
    {
        $variables = $aliyun['verification_variables'] ?? ['code'];
        if (! is_array($variables) || ! array_is_list($variables)) {
            return ['code'];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $variables),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function nullableResponseString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value;
    }

    private function mask(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        $value = (string) $value;
        if (strlen($value) <= 4) {
            return '****';
        }

        return str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4);
    }
}
