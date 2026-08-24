<?php

namespace App\Modules\Auth\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Models\WechatProviderCredential;
use App\Modules\Auth\Models\WechatVerificationFile;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WechatProviderSettingsService
{
    public const MINI_SETTING_KEY = 'auth.wechat_mini';

    public const OA_SETTING_KEY = 'auth.wechat_official_account';

    private const PROVIDERS = ['mini', 'official_account'];

    private const SECRET_FIELDS = ['app_secret', 'token', 'encoding_aes_key'];

    /** @var array<string,array<string,mixed>> */
    private array $effectiveCache = [];

    public function __construct(private readonly SystemSettingService $systemSettings) {}

    /** @return array{driver:string,app_id:string,app_secret:?string,token:?string,encoding_aes_key:?string,timeout_seconds:int} */
    public function effective(string $provider): array
    {
        $this->assertProvider($provider);
        if (isset($this->effectiveCache[$provider])) {
            return $this->effectiveCache[$provider];
        }
        $resolved = $this->resolvedConfiguration($provider);
        $credentials = $this->resolvedCredentials($provider);

        return $this->effectiveCache[$provider] = [
            'driver' => (string) ($resolved['configuration']['driver'] ?? 'local'),
            'app_id' => trim((string) ($resolved['configuration']['app_id'] ?? '')),
            'app_secret' => $credentials['app_secret'],
            'token' => $credentials['token'],
            'encoding_aes_key' => $credentials['encoding_aes_key'],
            'timeout_seconds' => (int) ($resolved['configuration']['timeout_seconds'] ?? 5),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'mini' => $this->providerSnapshot('mini'),
            'official_account' => $this->providerSnapshot('official_account'),
            'verification_files' => WechatVerificationFile::query()
                ->latest('updated_at')
                ->get()
                ->map(fn (WechatVerificationFile $file): array => $this->presentVerificationFile($file))
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $input
     * @return array{driver:string,app_id:string,timeout_seconds:int}
     */
    public function buildSettings(string $provider, array $input): array
    {
        $this->assertProvider($provider);
        $current = Arr::only($this->effective($provider), ['driver', 'app_id', 'timeout_seconds']);
        $unknown = array_diff(array_keys($input), ['driver', 'app_id', 'timeout_seconds']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['settings' => '包含不支持的微信配置字段。']);
        }

        foreach (['driver', 'app_id', 'timeout_seconds'] as $field) {
            if (array_key_exists($field, $input)) {
                $current[$field] = $input[$field];
            }
        }

        return $this->validateSettings($current);
    }

    /** @param array<string, mixed> $settings
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>
     */
    public function update(string $provider, array $settings, array $credentials, string $reason, AdminUser $actor): array
    {
        $this->assertProvider($provider);
        $settings = $this->validateSettings($settings);
        $credentials = Arr::only($credentials, self::SECRET_FIELDS);

        DB::transaction(function () use ($provider, $settings, $credentials, $reason, $actor): void {
            $settingKey = $this->settingKey($provider);
            $this->systemSettings->saveDraft($settingKey, $settings, $reason, $actor);
            $this->systemSettings->publish($settingKey, $actor);
            $this->saveCredentials($provider, $credentials);
        });
        unset($this->effectiveCache[$provider]);

        return $this->providerSnapshot($provider);
    }

    /** @return array{accepted:bool,tested_at:string,message:string} */
    public function test(string $provider): array
    {
        $configuration = $this->effective($provider);
        if ($configuration['driver'] !== 'wechat'
            || $configuration['app_id'] === ''
            || blank($configuration['app_secret'])) {
            throw ValidationException::withMessages(['provider' => '请先保存真实微信模式、AppID 和 AppSecret。']);
        }

        $record = WechatProviderCredential::query()->firstOrNew(['provider' => $provider]);
        try {
            $response = Http::acceptJson()
                ->timeout($configuration['timeout_seconds'])
                ->get('https://api.weixin.qq.com/cgi-bin/token', [
                    'grant_type' => 'client_credential',
                    'appid' => $configuration['app_id'],
                    'secret' => $configuration['app_secret'],
                ]);
            $payload = $response->json();
            if ($response->failed() || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
                throw new RuntimeException('Wechat rejected the credentials.');
            }
        } catch (ConnectionException|RuntimeException $exception) {
            report($exception);
            $message = '微信连接测试失败，请检查 AppID、AppSecret、IP 白名单和网络状态。';
            $this->recordTest($record, 'failed', $message);
            throw ValidationException::withMessages(['provider' => $message]);
        }

        $message = '微信接口凭据验证通过。';
        $this->recordTest($record, 'success', $message);

        return ['accepted' => true, 'tested_at' => now()->toIso8601String(), 'message' => $message];
    }

    /** @return array<string, mixed> */
    public function storeVerificationFile(UploadedFile $upload, AdminUser $actor): array
    {
        $filename = $upload->getClientOriginalName();
        if (! preg_match('/^MP_verify_[A-Za-z0-9_-]{6,64}\.txt$/', $filename)) {
            throw ValidationException::withMessages([
                'file' => '仅支持微信提供的 MP_verify_xxx.txt 验证文件。',
            ]);
        }
        if (! $upload->isValid() || $upload->getSize() === false || $upload->getSize() > 4096) {
            throw ValidationException::withMessages(['file' => '验证文件无效或超过 4 KB。']);
        }
        $content = trim((string) file_get_contents($upload->getRealPath()));
        if ($content === '' || strlen($content) > 512 || ! preg_match('/^[A-Za-z0-9_-]+$/', $content)) {
            throw ValidationException::withMessages(['file' => '验证文件内容格式不正确。']);
        }

        $file = WechatVerificationFile::query()->updateOrCreate(
            ['filename' => $filename],
            ['content' => $content, 'uploaded_by_admin_id' => $actor->id],
        );

        return $this->presentVerificationFile($file);
    }

    public function deleteVerificationFile(string $filename): bool
    {
        if (! preg_match('/^MP_verify_[A-Za-z0-9_-]{6,64}\.txt$/', $filename)) {
            return false;
        }

        return WechatVerificationFile::query()->where('filename', $filename)->delete() > 0;
    }

    /** @return array<string, mixed> */
    private function providerSnapshot(string $provider): array
    {
        $configuration = $this->effective($provider);
        $resolved = $this->resolvedConfiguration($provider);
        $record = WechatProviderCredential::query()->where('provider', $provider)->first();

        $secrets = [];
        foreach (self::SECRET_FIELDS as $field) {
            $stored = $this->storedCredential($record, $field);
            $environment = $this->environmentCredentials($provider)[$field];
            $value = filled($stored) ? $stored : $environment;
            $secrets[$field] = [
                'configured' => filled($value),
                'masked' => $this->mask($value),
                'source' => filled($stored) ? 'backend' : (filled($environment) ? 'env' : 'none'),
            ];
        }

        return [
            'driver' => $configuration['driver'],
            'app_id' => $configuration['app_id'],
            'app_id_source' => $resolved['source'],
            'app_secret' => $secrets['app_secret'],
            'token' => $secrets['token'],
            'encoding_aes_key' => $secrets['encoding_aes_key'],
            'timeout_seconds' => $configuration['timeout_seconds'],
            'ready' => $configuration['driver'] === 'wechat'
                && $configuration['app_id'] !== ''
                && filled($configuration['app_secret']),
            'last_tested_at' => $record?->last_tested_at?->toIso8601String(),
            'last_test_status' => $record?->last_test_status,
            'last_test_message' => $record?->last_test_message,
        ];
    }

    /** @return array{configuration:array<string,mixed>,source:string} */
    private function resolvedConfiguration(string $provider): array
    {
        $environment = $this->environmentConfiguration($provider);
        try {
            $reading = $this->systemSettings->read($this->settingKey($provider));
            if ($reading['source'] === 'published' && is_array($reading['value'])) {
                return [
                    'configuration' => array_replace($environment, $reading['value']),
                    'source' => 'backend',
                ];
            }
        } catch (Throwable) {
            // Keep older deployments working until their migration completes.
        }

        return ['configuration' => $environment, 'source' => 'env'];
    }

    /** @return array<string, mixed> */
    private function environmentConfiguration(string $provider): array
    {
        return [
            'driver' => (string) config('wechat_auth.driver', 'local'),
            'app_id' => (string) config("wechat_auth.{$provider}.app_id", ''),
            'timeout_seconds' => max(1, min(30, (int) config('wechat_auth.timeout_seconds', 5))),
        ];
    }

    /** @return array{app_secret:?string,token:?string,encoding_aes_key:?string} */
    private function resolvedCredentials(string $provider): array
    {
        $record = WechatProviderCredential::query()->where('provider', $provider)->first();
        $environment = $this->environmentCredentials($provider);
        $credentials = [];
        foreach (self::SECRET_FIELDS as $field) {
            $stored = $this->storedCredential($record, $field);
            $credentials[$field] = filled($stored) ? $stored : $environment[$field];
        }

        return $credentials;
    }

    /** @return array{app_secret:?string,token:?string,encoding_aes_key:?string} */
    private function environmentCredentials(string $provider): array
    {
        return [
            'app_secret' => $this->nullableString(config("wechat_auth.{$provider}.app_secret")),
            'token' => $provider === 'official_account'
                ? $this->nullableString(config('wechat_auth.official_account.token'))
                : null,
            'encoding_aes_key' => $provider === 'official_account'
                ? $this->nullableString(config('wechat_auth.official_account.encoding_aes_key'))
                : null,
        ];
    }

    /** @param array<string, mixed> $value
     * @return array{driver:string,app_id:string,timeout_seconds:int}
     */
    private function validateSettings(array $value): array
    {
        $driver = (string) ($value['driver'] ?? '');
        $appId = trim((string) ($value['app_id'] ?? ''));
        $timeout = $value['timeout_seconds'] ?? null;
        if (! in_array($driver, ['local', 'wechat'], true)) {
            throw ValidationException::withMessages(['driver' => '运行模式只能为本地模拟或真实微信。']);
        }
        if (strlen($appId) > 64 || ($appId !== '' && ! preg_match('/^wx[A-Za-z0-9]{6,62}$/', $appId))) {
            throw ValidationException::withMessages(['app_id' => 'AppID 格式不正确，应以 wx 开头。']);
        }
        if (! is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw ValidationException::withMessages(['timeout_seconds' => '请求超时必须为 1 至 30 秒的整数。']);
        }

        return ['driver' => $driver, 'app_id' => $appId, 'timeout_seconds' => $timeout];
    }

    /** @param array<string, mixed> $credentials */
    private function saveCredentials(string $provider, array $credentials): void
    {
        if ($credentials === []) {
            return;
        }
        $record = WechatProviderCredential::query()->firstOrNew(['provider' => $provider]);
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $credentials)) {
                $record->{$field} = $this->nullableString($credentials[$field]);
            }
        }
        $record->save();
    }

    private function recordTest(WechatProviderCredential $record, string $status, string $message): void
    {
        $record->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_message' => $message,
        ])->save();
    }

    private function storedCredential(?WechatProviderCredential $record, string $field): ?string
    {
        if ($record === null) {
            return null;
        }
        try {
            return $this->nullableString($record->{$field});
        } catch (Throwable $exception) {
            throw new RuntimeException('The stored WeChat credentials are unavailable.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function presentVerificationFile(WechatVerificationFile $file): array
    {
        return [
            'filename' => $file->filename,
            'public_url' => rtrim((string) config('app.url'), '/').'/'.$file->filename,
            'updated_at' => $file->updated_at?->toIso8601String(),
        ];
    }

    private function settingKey(string $provider): string
    {
        return $provider === 'mini' ? self::MINI_SETTING_KEY : self::OA_SETTING_KEY;
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => '不支持的微信应用类型。']);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function mask(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        $value = (string) $value;

        return strlen($value) <= 4 ? '****' : str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4);
    }
}
