<?php

namespace App\Modules\Community\Services;

use App\Modules\Community\Models\CommunityContentSecuritySetting;
use RuntimeException;
use Throwable;

class CommunityContentSecuritySettingsService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $record = $this->record();
        $configuration = $this->configuration();

        return [
            'enabled' => $configuration['enabled'],
            'provider' => $configuration['provider'],
            'region' => $configuration['region'],
            'endpoint' => $configuration['endpoint'],
            'service' => $configuration['service'],
            'aliyun' => [
                'configured' => $configuration['configured'],
                'source' => $configuration['source'],
                'access_key_id_masked' => $this->mask($configuration['access_key_id']),
                'access_key_secret_masked' => $configuration['access_key_secret'] === null ? null : '********',
            ],
            'last_tested_at' => $record?->last_tested_at?->toIso8601String(),
            'last_test_status' => $record?->last_test_status,
            'last_test_message' => $record?->last_test_message,
        ];
    }

    /** @return array{enabled:bool,provider:string,region:string,endpoint:string,service:string,access_key_id:?string,access_key_secret:?string,configured:bool,source:string} */
    public function configuration(): array
    {
        $record = $this->record();
        $storedId = $this->storedValue($record, 'access_key_id');
        $storedSecret = $this->storedValue($record, 'access_key_secret');
        $environmentId = $this->nullableString(config('services.green.access_key_id'));
        $environmentSecret = $this->nullableString(config('services.green.access_key_secret'));
        $useStored = filled($storedId) && filled($storedSecret);
        $useEnvironment = ! $useStored && filled($environmentId) && filled($environmentSecret);
        $accessKeyId = $useStored ? $storedId : ($useEnvironment ? $environmentId : null);
        $accessKeySecret = $useStored ? $storedSecret : ($useEnvironment ? $environmentSecret : null);

        return [
            'enabled' => (bool) ($record?->enabled ?? true),
            'provider' => $record?->provider ?? 'wechat',
            'region' => $record?->region ?? 'cn-shanghai',
            'endpoint' => $record?->endpoint ?? 'green-cip.cn-shanghai.aliyuncs.com',
            'service' => $record?->service ?? 'ugc_moderation_byllm',
            'access_key_id' => $accessKeyId,
            'access_key_secret' => $accessKeySecret,
            'configured' => filled($accessKeyId) && filled($accessKeySecret),
            'source' => filled($storedId) && filled($storedSecret) ? 'backend' : (filled($environmentId) && filled($environmentSecret) ? 'env' : 'none'),
        ];
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(array $data): array
    {
        $record = $this->record() ?? new CommunityContentSecuritySetting(['name' => 'default']);
        $record->provider = $data['provider'];
        $record->enabled = (bool) $data['enabled'];
        $record->region = $data['region'];
        $record->endpoint = $data['endpoint'];
        $record->service = $data['service'];
        if ((bool) ($data['clear_credentials'] ?? false)) {
            $record->access_key_id = null;
            $record->access_key_secret = null;
        } else {
            if ($this->nullableString($data['access_key_id'] ?? null) !== null) {
                $record->access_key_id = trim((string) $data['access_key_id']);
            }
            if ($this->nullableString($data['access_key_secret'] ?? null) !== null) {
                $record->access_key_secret = trim((string) $data['access_key_secret']);
            }
        }
        $record->last_tested_at = null;
        $record->last_test_status = null;
        $record->last_test_message = null;
        $record->save();

        return $this->snapshot();
    }

    public function recordTest(string $status, string $message): void
    {
        $record = $this->record() ?? new CommunityContentSecuritySetting(['name' => 'default']);
        $record->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_message' => $message,
        ])->save();
    }

    private function record(): ?CommunityContentSecuritySetting
    {
        return CommunityContentSecuritySetting::query()->where('name', 'default')->first();
    }

    private function storedValue(?CommunityContentSecuritySetting $record, string $field): ?string
    {
        if ($record === null) {
            return null;
        }
        try {
            return $this->nullableString($record->{$field});
        } catch (Throwable $exception) {
            throw new RuntimeException('已保存的内容安全凭证不可用。', 0, $exception);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function mask(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return strlen($value) <= 4 ? '****' : str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4);
    }
}
