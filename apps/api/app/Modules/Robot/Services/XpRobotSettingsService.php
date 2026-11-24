<?php

namespace App\Modules\Robot\Services;

use App\Modules\Robot\Exceptions\XpRobotException;
use App\Modules\Robot\Models\RobotXpCredential;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class XpRobotSettingsService
{
    private const PROVIDER = 'xp';

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $record = $this->record();
        $stored = $this->storedApiKey($record);
        $environment = $this->nullableString(config('services.xp.api_key'));
        $apiKey = filled($stored) ? $stored : $environment;

        return [
            'configured' => filled($apiKey),
            'masked' => $this->mask($apiKey),
            'source' => filled($stored) ? 'backend' : (filled($environment) ? 'env' : 'none'),
            'base_url' => rtrim((string) config('services.xp.base_url'), '/'),
            'last_tested_at' => $record?->last_tested_at?->toIso8601String(),
            'last_test_status' => $record?->last_test_status,
            'last_test_message' => $record?->last_test_message,
        ];
    }

    public function apiKey(): string
    {
        $stored = $this->storedApiKey($this->record());
        $apiKey = filled($stored) ? $stored : $this->nullableString(config('services.xp.api_key'));
        if (! filled($apiKey)) {
            throw ValidationException::withMessages([
                'api_key' => '请先配置 XP API Key，或通过 XP_API_KEY 环境变量提供。',
            ]);
        }

        return $apiKey;
    }

    /** @return array<string, mixed> */
    public function update(?string $apiKey, bool $clear): array
    {
        $record = $this->record() ?? new RobotXpCredential(['provider' => self::PROVIDER]);
        if ($clear) {
            $record->api_key = null;
        } elseif (filled($apiKey)) {
            $record->api_key = trim((string) $apiKey);
        }
        $record->last_tested_at = null;
        $record->last_test_status = null;
        $record->last_test_message = null;
        $record->save();

        return $this->snapshot();
    }

    /** @return array{accepted:bool,tested_at:string,message:string,device_count:int,online_count:int} */
    public function test(XpRobotClient $client): array
    {
        try {
            $devices = $client->devices();
        } catch (XpRobotException $exception) {
            $message = 'XP 连接测试失败，请检查 API Key、服务地址和网络状态。';
            $this->recordTest('failed', $message);
            throw $exception;
        }

        $message = 'XP 连接成功，设备列表读取正常。';
        $this->recordTest('success', $message);

        return [
            'accepted' => true,
            'tested_at' => now()->toIso8601String(),
            'message' => $message,
            'device_count' => $devices['total'],
            'online_count' => $devices['online_count'],
        ];
    }

    private function record(): ?RobotXpCredential
    {
        return RobotXpCredential::query()->where('provider', self::PROVIDER)->first();
    }

    private function storedApiKey(?RobotXpCredential $record): ?string
    {
        if ($record === null) {
            return null;
        }
        try {
            return $this->nullableString($record->api_key);
        } catch (Throwable $exception) {
            throw new RuntimeException('The stored XP credential is unavailable.', 0, $exception);
        }
    }

    private function recordTest(string $status, string $message): void
    {
        $record = $this->record() ?? new RobotXpCredential(['provider' => self::PROVIDER]);
        $record->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_message' => $message,
        ])->save();
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
