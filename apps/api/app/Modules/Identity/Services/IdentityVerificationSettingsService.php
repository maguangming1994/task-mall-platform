<?php

namespace App\Modules\Identity\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Identity\Models\IdentityProviderCredential;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class IdentityVerificationSettingsService
{
    public const SETTING_KEY = 'identity.verification';

    private const CREDENTIAL_FIELDS = ['access_key_id', 'access_key_secret', 'security_token'];

    public function __construct(private readonly SystemSettingService $systemSettings) {}

    /** @return array<string,mixed> */
    public function effective(): array
    {
        $value = $this->defaults();
        $source = 'default';
        try {
            $reading = $this->systemSettings->read(self::SETTING_KEY);
            if (is_array($reading['value'])) {
                $value = array_replace_recursive($value, $reading['value']);
                $source = $reading['source'];
            }
        } catch (Throwable) {
            // Keep verification disabled until its setting definition exists.
        }

        return [...$this->validate($value), 'source' => $source];
    }

    /** @return array{access_key_id:?string,access_key_secret:?string,security_token:?string} */
    public function aliyunCredentials(): array
    {
        $record = IdentityProviderCredential::query()->where('provider', 'aliyun')->first();
        $result = [];
        foreach (self::CREDENTIAL_FIELDS as $field) {
            try {
                $value = $record?->{$field};
            } catch (Throwable $exception) {
                throw new RuntimeException('Stored Aliyun identity credentials are unavailable.', previous: $exception);
            }
            $result[$field] = filled($value) ? (string) $value : null;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $configuration = $this->effective();
        $credentials = $this->aliyunCredentials();
        $secretStates = [];
        foreach (self::CREDENTIAL_FIELDS as $field) {
            $secretStates[$field] = [
                'configured' => filled($credentials[$field]),
                'masked' => $this->mask($credentials[$field]),
                'source' => filled($credentials[$field]) ? 'backend' : 'none',
            ];
        }

        return [
            ...$configuration,
            ...$secretStates,
            'ready' => $configuration['driver'] !== 'aliyun'
                || (filled($credentials['access_key_id']) && filled($credentials['access_key_secret'])),
        ];
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $credentials
     * @return array<string,mixed>
     */
    public function update(array $input, array $credentials, string $reason, AdminUser $actor): array
    {
        $value = $this->validate($input);
        DB::transaction(function () use ($value, $credentials, $reason, $actor): void {
            $this->systemSettings->saveDraft(self::SETTING_KEY, $value, $reason, $actor);
            $this->systemSettings->publish(self::SETTING_KEY, $actor);
            $this->saveCredentials($credentials);
        });

        return $this->snapshot();
    }

    /** @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function validate(array $value): array
    {
        $driver = (string) ($value['driver'] ?? '');
        if (! in_array($driver, ['disabled', 'manual', 'aliyun'], true)) {
            throw ValidationException::withMessages(['driver' => '实名认证模式无效。']);
        }
        foreach (['collection_enabled', 'manual_review_enabled'] as $field) {
            if (! is_bool($value[$field] ?? null)) {
                throw ValidationException::withMessages([$field => '开关值格式不正确。']);
            }
        }
        if ($driver === 'manual' && ! $value['manual_review_enabled']) {
            throw ValidationException::withMessages(['manual_review_enabled' => '人工审核模式必须启用人工复核。']);
        }
        if ($driver !== 'disabled' && ! $value['collection_enabled']) {
            throw ValidationException::withMessages(['collection_enabled' => '启用实名认证时必须允许收集认证资料。']);
        }

        $aliyun = $value['aliyun'] ?? [];
        if (! is_array($aliyun) || array_is_list($aliyun)) {
            throw ValidationException::withMessages(['aliyun' => '阿里云配置格式不正确。']);
        }
        $region = trim((string) ($aliyun['region'] ?? ''));
        $endpoint = preg_replace('#^https?://#i', '', trim((string) ($aliyun['endpoint'] ?? ''))) ?: '';
        $endpoint = rtrim($endpoint, '/');
        $timeout = $aliyun['timeout_seconds'] ?? null;
        if ($region === '' || mb_strlen($region) > 64) {
            throw ValidationException::withMessages(['aliyun.region' => '阿里云地域不能为空。']);
        }
        if ($endpoint === '' || mb_strlen($endpoint) > 255 || filter_var('https://'.$endpoint, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['aliyun.endpoint' => '阿里云 Endpoint 格式不正确。']);
        }
        if (! is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw ValidationException::withMessages(['aliyun.timeout_seconds' => '超时时间必须为 1 至 30 秒。']);
        }

        return [
            'driver' => $driver,
            'collection_enabled' => (bool) $value['collection_enabled'],
            'manual_review_enabled' => (bool) $value['manual_review_enabled'],
            'aliyun' => ['region' => $region, 'endpoint' => $endpoint, 'timeout_seconds' => $timeout],
        ];
    }

    /** @param array<string,mixed> $credentials */
    private function saveCredentials(array $credentials): void
    {
        if ($credentials === []) {
            return;
        }
        $record = IdentityProviderCredential::query()->firstOrNew(['provider' => 'aliyun']);
        foreach (self::CREDENTIAL_FIELDS as $field) {
            $clearField = 'clear_'.$field;
            if (($credentials[$clearField] ?? false) === true) {
                $record->{$field} = null;
            } elseif (filled($credentials[$field] ?? null)) {
                $record->{$field} = trim((string) $credentials[$field]);
            }
        }
        $record->save();
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'driver' => 'disabled',
            'collection_enabled' => false,
            'manual_review_enabled' => false,
            'aliyun' => [
                'region' => 'cn-hangzhou',
                'endpoint' => 'cloudauth.aliyuncs.com',
                'timeout_seconds' => 8,
            ],
        ];
    }

    private function mask(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        if (strlen($value) <= 6) {
            return '****';
        }

        return substr($value, 0, 3).'****'.substr($value, -3);
    }
}
