<?php

namespace App\Modules\Payment\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Payment\Models\WechatPaymentCredential;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WechatPaymentSettingsService
{
    public const SETTING_KEY = 'payment.wechat';

    private const SECRET_FIELDS = ['api_v3_key', 'merchant_private_key', 'platform_certificate'];

    /** @var array<string,mixed>|null */
    private ?array $effectiveCache = null;

    public function __construct(
        private readonly SystemSettingService $systemSettings,
        private readonly WechatProviderSettingsService $wechatSettings,
    ) {}

    /** @return array<string, mixed> */
    public function effective(): array
    {
        if ($this->effectiveCache !== null) {
            return $this->effectiveCache;
        }
        $configuration = $this->resolvedConfiguration()['configuration'];
        $credentials = $this->resolvedCredentials();

        return $this->effectiveCache = [
            ...$configuration,
            ...$credentials,
            'mini_app_id' => $this->wechatSettings->effective('mini')['app_id'],
            'official_account_app_id' => $this->wechatSettings->effective('official_account')['app_id'],
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $configuration = $this->effective();
        $record = WechatPaymentCredential::query()->where('provider', 'wechat_pay_v3')->first();
        $environment = $this->environmentCredentials();
        $secretStates = [];
        foreach (self::SECRET_FIELDS as $field) {
            $stored = $this->storedValue($record, $field);
            $fallback = $environment[$field];
            $value = filled($stored) ? $stored : $fallback;
            $secretStates[$field] = [
                'configured' => filled($value),
                'masked' => $field === 'api_v3_key' ? $this->mask($value) : null,
                'source' => filled($stored) ? 'backend' : (filled($fallback) ? 'env' : 'none'),
            ];
        }

        return [
            'driver' => $configuration['driver'],
            'merchant_id' => $configuration['merchant_id'],
            'merchant_certificate_serial' => $configuration['merchant_certificate_serial'],
            'notify_url' => $configuration['notify_url'],
            'refund_notify_url' => $configuration['refund_notify_url'],
            'timeout_seconds' => $configuration['timeout_seconds'],
            'api_v3_key' => $secretStates['api_v3_key'],
            'merchant_private_key' => $secretStates['merchant_private_key'],
            'platform_certificate' => $secretStates['platform_certificate'],
            // 场景 AppID 绑定：自动取自小程序/公众号配置，无需重复填写。
            'mini_app_id' => (string) ($configuration['mini_app_id'] ?? ''),
            'official_account_app_id' => (string) ($configuration['official_account_app_id'] ?? ''),
            'ready' => $this->isReady($configuration),
            'last_tested_at' => $record?->last_tested_at?->toIso8601String(),
            'last_test_status' => $record?->last_test_status,
            'last_test_message' => $record?->last_test_message,
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function buildSettings(array $input): array
    {
        $fields = ['driver', 'merchant_id', 'merchant_certificate_serial', 'notify_url', 'refund_notify_url', 'timeout_seconds'];
        $unknown = array_diff(array_keys($input), $fields);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['settings' => '包含不支持的微信支付配置字段。']);
        }
        $current = Arr::only($this->effective(), $fields);
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $current[$field] = $input[$field];
            }
        }

        return $this->validateSettings($current);
    }

    /** @param array<string, mixed> $settings
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function update(array $settings, array $credentials, string $reason, AdminUser $actor): array
    {
        $settings = $this->validateSettings($settings);
        $credentials = Arr::only($credentials, self::SECRET_FIELDS);
        $this->validateCredentials($credentials);
        $this->assertEnabledConfigurationComplete($settings, $credentials);

        DB::transaction(function () use ($settings, $credentials, $reason, $actor): void {
            $this->systemSettings->saveDraft(self::SETTING_KEY, $settings, $reason, $actor);
            $this->systemSettings->publish(self::SETTING_KEY, $actor);
            if ($credentials !== []) {
                $record = WechatPaymentCredential::query()->firstOrNew(['provider' => 'wechat_pay_v3']);
                foreach (self::SECRET_FIELDS as $field) {
                    if (array_key_exists($field, $credentials)) {
                        $record->{$field} = $this->nullableString($credentials[$field]);
                    }
                }
                $record->save();
            }
        });
        $this->effectiveCache = null;

        return $this->snapshot();
    }

    /** @return array{accepted:bool,tested_at:string,message:string} */
    public function test(): array
    {
        $config = $this->effective();
        if (! $this->isReady($config)) {
            throw ValidationException::withMessages(['payment' => '请先完整保存微信支付商户参数、密钥和证书。']);
        }
        $path = '/v3/certificates';
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $messageToSign = "GET\n{$path}\n{$timestamp}\n{$nonce}\n\n";
        if (! openssl_sign($messageToSign, $signature, (string) $config['merchant_private_key'], OPENSSL_ALGO_SHA256)) {
            throw ValidationException::withMessages(['merchant_private_key' => '商户私钥无法用于签名。']);
        }
        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $config['merchant_id'], $nonce, $timestamp, $config['merchant_certificate_serial'], base64_encode($signature),
        );
        $record = WechatPaymentCredential::query()->firstOrNew(['provider' => 'wechat_pay_v3']);
        try {
            $response = Http::acceptJson()->withHeaders(['Authorization' => $authorization, 'User-Agent' => 'task-mall-api/1.0'])
                ->timeout((int) $config['timeout_seconds'])->get('https://api.mch.weixin.qq.com'.$path);
            if (! $response->successful()) {
                throw new RuntimeException('Wechat Pay rejected the credentials.');
            }
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            $message = '微信支付连接测试失败，请检查商户号、证书序列号、私钥和平台权限。';
            $this->recordTest($record, 'failed', $message);
            throw ValidationException::withMessages(['payment' => $message]);
        }
        $message = '微信支付商户接口验证通过。';
        $this->recordTest($record, 'success', $message);

        return ['accepted' => true, 'tested_at' => now()->toIso8601String(), 'message' => $message];
    }

    /** @return array{configuration:array<string,mixed>,source:string} */
    private function resolvedConfiguration(): array
    {
        $environment = [
            'driver' => (string) config('wechat_payment.driver', 'local'),
            'merchant_id' => (string) config('wechat_payment.merchant_id', ''),
            'merchant_certificate_serial' => (string) config('wechat_payment.merchant_certificate_serial', ''),
            'notify_url' => (string) config('wechat_payment.notify_url', ''),
            'refund_notify_url' => (string) config('wechat_payment.refund_notify_url', ''),
            'timeout_seconds' => max(1, min(30, (int) config('wechat_payment.timeout_seconds', 8))),
        ];
        try {
            $reading = $this->systemSettings->read(self::SETTING_KEY);
            if ($reading['source'] === 'published' && is_array($reading['value'])) {
                return ['configuration' => array_replace($environment, $reading['value']), 'source' => 'backend'];
            }
        } catch (Throwable) {
            // Environment configuration remains the compatibility fallback.
        }

        return ['configuration' => $environment, 'source' => 'env'];
    }

    /** @return array{api_v3_key:?string,merchant_private_key:?string,platform_certificate:?string} */
    private function resolvedCredentials(): array
    {
        $record = WechatPaymentCredential::query()->where('provider', 'wechat_pay_v3')->first();
        $environment = $this->environmentCredentials();
        $values = [];
        foreach (self::SECRET_FIELDS as $field) {
            $stored = $this->storedValue($record, $field);
            $values[$field] = filled($stored) ? $stored : $environment[$field];
        }

        return $values;
    }

    /** @return array{api_v3_key:?string,merchant_private_key:?string,platform_certificate:?string} */
    private function environmentCredentials(): array
    {
        return [
            'api_v3_key' => $this->nullableString(config('wechat_payment.api_v3_key')),
            'merchant_private_key' => $this->readEnvironmentFile(config('wechat_payment.merchant_private_key_path')),
            'platform_certificate' => $this->readEnvironmentFile(config('wechat_payment.platform_certificate_path')),
        ];
    }

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function validateSettings(array $settings): array
    {
        $driver = (string) ($settings['driver'] ?? '');
        if (! in_array($driver, ['local', 'wechat'], true)) {
            throw ValidationException::withMessages(['driver' => '支付驱动只能为本地模拟或真实微信。']);
        }
        foreach (['merchant_id', 'merchant_certificate_serial'] as $field) {
            $settings[$field] = trim((string) ($settings[$field] ?? ''));
            if (strlen($settings[$field]) > 128 || ($settings[$field] !== '' && ! preg_match('/^[A-Za-z0-9]+$/', $settings[$field]))) {
                throw ValidationException::withMessages([$field => '字段格式不正确。']);
            }
        }
        foreach (['notify_url', 'refund_notify_url'] as $field) {
            $settings[$field] = trim((string) ($settings[$field] ?? ''));
            if ($settings[$field] !== '' && (! str_starts_with($settings[$field], 'https://') || filter_var($settings[$field], FILTER_VALIDATE_URL) === false)) {
                throw ValidationException::withMessages([$field => '回调地址必须为有效 HTTPS 地址。']);
            }
        }
        if (! is_int($settings['timeout_seconds'] ?? null) || $settings['timeout_seconds'] < 1 || $settings['timeout_seconds'] > 30) {
            throw ValidationException::withMessages(['timeout_seconds' => '请求超时必须为 1 至 30 秒的整数。']);
        }

        return Arr::only(['driver' => $driver, ...$settings], [
            'driver', 'merchant_id', 'merchant_certificate_serial', 'notify_url', 'refund_notify_url', 'timeout_seconds',
        ]);
    }

    /** @param array<string, mixed> $credentials */
    private function validateCredentials(array $credentials): void
    {
        if (filled($credentials['api_v3_key'] ?? null) && strlen((string) $credentials['api_v3_key']) !== 32) {
            throw ValidationException::withMessages(['api_v3_key' => 'APIv3 密钥必须正好为 32 个字节。']);
        }
        if (filled($credentials['merchant_private_key'] ?? null) && openssl_pkey_get_private((string) $credentials['merchant_private_key']) === false) {
            throw ValidationException::withMessages(['merchant_private_key' => '上传内容不是有效的 PEM 商户私钥。']);
        }
        if (filled($credentials['platform_certificate'] ?? null) && openssl_x509_read((string) $credentials['platform_certificate']) === false) {
            throw ValidationException::withMessages(['platform_certificate' => '上传内容不是有效的 PEM 平台证书。']);
        }
    }

    /**
     * 启用微信支付前必须补齐商户参数、密钥、证书、回调地址，并至少绑定一个场景 AppID。
     * 缺失项以中文逐条列出，避免保存成功但线上不可用。
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $credentials
     */
    private function assertEnabledConfigurationComplete(array $settings, array $credentials): void
    {
        if (($settings['driver'] ?? '') !== 'wechat') {
            return;
        }
        $merged = [...$this->effective(), ...$settings];
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $credentials)) {
                $merged[$field] = $credentials[$field];
            }
        }
        $required = [
            'merchant_id' => '商户号',
            'merchant_certificate_serial' => '商户证书序列号',
            'api_v3_key' => 'APIv3 密钥',
            'merchant_private_key' => '商户私钥',
            'platform_certificate' => '微信支付平台证书',
            'notify_url' => '支付回调地址',
        ];
        $missing = [];
        foreach ($required as $field => $label) {
            if (blank($merged[$field] ?? null)) {
                $missing[] = $label;
            }
        }
        if (blank($this->wechatSettings->effective('mini')['app_id'])
            && blank($this->wechatSettings->effective('official_account')['app_id'])) {
            $missing[] = '小程序或公众号 AppID（场景绑定）';
        }
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'driver' => '启用微信支付前请补齐：'.implode('、', $missing).'。',
            ]);
        }
    }

    /** @param array<string, mixed> $config */
    private function isReady(array $config): bool
    {
        return $config['driver'] === 'wechat'
            && filled($config['merchant_id'])
            && filled($config['merchant_certificate_serial'])
            && filled($config['api_v3_key'])
            && filled($config['merchant_private_key'])
            && filled($config['platform_certificate'])
            && filled($config['notify_url']);
    }

    private function storedValue(?WechatPaymentCredential $record, string $field): ?string
    {
        if ($record === null) {
            return null;
        }
        try {
            return $this->nullableString($record->{$field});
        } catch (Throwable $exception) {
            throw new RuntimeException('The stored WeChat Pay credentials are unavailable.', 0, $exception);
        }
    }

    private function readEnvironmentFile(mixed $path): ?string
    {
        $path = trim((string) $path);

        return $path !== '' && is_file($path) ? $this->nullableString(file_get_contents($path)) : null;
    }

    private function recordTest(WechatPaymentCredential $record, string $status, string $message): void
    {
        $record->forceFill(['last_tested_at' => now(), 'last_test_status' => $status, 'last_test_message' => $message])->save();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function mask(mixed $value): ?string
    {
        return filled($value) ? '****************************'.substr((string) $value, -4) : null;
    }
}
