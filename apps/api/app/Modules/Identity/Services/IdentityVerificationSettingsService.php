<?php

namespace App\Modules\Identity\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IdentityVerificationSettingsService
{
    public const SETTING_KEY = 'identity.verification';

    public function __construct(private readonly SystemSettingService $systemSettings) {}

    /** @return array{driver:string,collection_enabled:bool,manual_review_enabled:bool,source:string} */
    public function effective(): array
    {
        $value = ['driver' => 'disabled', 'collection_enabled' => false, 'manual_review_enabled' => false];
        $source = 'default';
        try {
            $reading = $this->systemSettings->read(self::SETTING_KEY);
            if (is_array($reading['value'])) {
                $value = array_replace($value, $reading['value']);
                $source = $reading['source'];
            }
        } catch (Throwable) {
            // The feature remains disabled until the migration is installed.
        }

        return [...$this->validate($value), 'source' => $source];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $input, string $reason, AdminUser $actor): array
    {
        $value = $this->validate($input);
        DB::transaction(function () use ($value, $reason, $actor): void {
            $this->systemSettings->saveDraft(self::SETTING_KEY, $value, $reason, $actor);
            $this->systemSettings->publish(self::SETTING_KEY, $actor);
        });

        return [...$value, 'source' => 'backend'];
    }

    /** @param array<string, mixed> $value
     * @return array{driver:string,collection_enabled:bool,manual_review_enabled:bool}
     */
    private function validate(array $value): array
    {
        $driver = (string) ($value['driver'] ?? '');
        if (! in_array($driver, ['disabled', 'manual'], true)) {
            throw ValidationException::withMessages(['driver' => '当前系统仅支持关闭或人工审核实名认证。']);
        }
        foreach (['collection_enabled', 'manual_review_enabled'] as $field) {
            if (! is_bool($value[$field] ?? null)) {
                throw ValidationException::withMessages([$field => '开关值格式不正确。']);
            }
        }
        if ($driver === 'manual' && ! $value['manual_review_enabled']) {
            throw ValidationException::withMessages(['manual_review_enabled' => '人工审核模式必须开启人工复核。']);
        }

        return [
            'driver' => $driver,
            'collection_enabled' => (bool) $value['collection_enabled'],
            'manual_review_enabled' => (bool) $value['manual_review_enabled'],
        ];
    }
}
