<?php

namespace App\Modules\Settings\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Settings\Events\SystemSettingPublished;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingDraft;
use App\Modules\Settings\Models\SystemSettingVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemSettingService
{
    public function __construct(private readonly SystemSettingValidator $validator) {}

    /** @return array{value:mixed,version:int|null,source:string} */
    public function read(string $settingKey): array
    {
        $activeKey = 'system-setting:active:'.$settingKey;
        $lastGoodKey = 'system-setting:last-good:'.$settingKey;

        try {
            $resolved = Cache::rememberForever($activeKey, function () use ($settingKey): array {
                $definition = $this->definition($settingKey);
                $version = $definition->versions()->latest('version')->first();

                return [
                    'value' => $version?->value ?? $definition->default_value,
                    'version' => $version?->version,
                    'source' => $version === null ? 'default' : 'published',
                ];
            });
            Cache::forever($lastGoodKey, $resolved);

            return $resolved;
        } catch (Throwable $exception) {
            $lastGood = Cache::get($lastGoodKey);
            if (is_array($lastGood) && array_key_exists('value', $lastGood)) {
                return $lastGood;
            }

            throw $exception;
        }
    }

    public function definition(string $settingKey): SystemSettingDefinition
    {
        $definition = SystemSettingDefinition::query()
            ->where('setting_key', $settingKey)
            ->where('enabled', true)
            ->first();
        if ($definition === null) {
            throw new SettingException('SETTING_NOT_FOUND', '配置项不存在或已停用。', 404);
        }

        return $definition;
    }

    public function saveDraft(
        string $settingKey,
        mixed $value,
        string $reason,
        AdminUser $actor,
        ?int $expectedLockVersion = null,
        ?SystemSettingVersion $rollbackSource = null,
    ): SystemSettingDraft {
        $definition = $this->definition($settingKey);
        $value = $this->validator->validate($definition, $value);

        return DB::transaction(function () use ($definition, $value, $reason, $actor, $expectedLockVersion, $rollbackSource): SystemSettingDraft {
            $draft = SystemSettingDraft::query()
                ->where('setting_definition_id', $definition->id)
                ->lockForUpdate()
                ->first();
            if ($draft !== null && $expectedLockVersion !== null && $draft->lock_version !== $expectedLockVersion) {
                throw new SettingException(
                    'SETTING_DRAFT_CONFLICT',
                    '配置草稿已被其他管理员更新，请刷新后重试。',
                    409,
                    ['current_lock_version' => $draft->lock_version],
                );
            }

            $current = $definition->versions()->latest('version')->first();
            $reviewStatus = $definition->risk_level === 'high' ? 'pending' : 'not_required';
            $attributes = [
                'value' => $value,
                'base_version_id' => $current?->id,
                'rollback_source_version_id' => $rollbackSource?->id,
                'reason' => $reason,
                'review_status' => $reviewStatus,
                'updated_by_admin_id' => $actor->id,
                'reviewed_by_admin_id' => null,
                'review_comment' => null,
                'reviewed_at' => null,
                'lock_version' => ($draft?->lock_version ?? 0) + 1,
            ];
            if ($draft === null) {
                $draft = SystemSettingDraft::query()->create([
                    ...$attributes,
                    'setting_definition_id' => $definition->id,
                    'created_by_admin_id' => $actor->id,
                ]);
            } else {
                $draft->update($attributes);
            }

            return $draft->fresh(['definition', 'baseVersion', 'rollbackSourceVersion', 'reviewedBy']);
        });
    }

    public function review(string $settingKey, string $decision, string $comment, AdminUser $reviewer): SystemSettingDraft
    {
        $definition = $this->definition($settingKey);
        if ($definition->risk_level !== 'high') {
            throw new SettingException('SETTING_REVIEW_NOT_REQUIRED', '该配置项不需要二次审核。', 409);
        }

        return DB::transaction(function () use ($definition, $decision, $comment, $reviewer): SystemSettingDraft {
            $draft = SystemSettingDraft::query()
                ->where('setting_definition_id', $definition->id)
                ->lockForUpdate()
                ->first();
            if ($draft === null || $draft->review_status !== 'pending') {
                throw new SettingException('SETTING_REVIEW_STATE_INVALID', '当前没有待审核的配置草稿。', 409);
            }
            if (in_array($reviewer->id, [$draft->created_by_admin_id, $draft->updated_by_admin_id], true)) {
                throw new SettingException('SETTING_FOUR_EYES_REQUIRED', '高风险配置必须由另一位管理员审核。', 409);
            }
            $draft->update([
                'review_status' => $decision === 'approve' ? 'approved' : 'rejected',
                'reviewed_by_admin_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
                'lock_version' => $draft->lock_version + 1,
            ]);

            return $draft->fresh(['definition', 'baseVersion', 'rollbackSourceVersion', 'reviewedBy']);
        });
    }

    public function publish(string $settingKey, AdminUser $publisher): SystemSettingVersion
    {
        $definition = $this->definition($settingKey);
        $version = DB::transaction(function () use ($definition, $publisher): SystemSettingVersion {
            $draft = SystemSettingDraft::query()
                ->where('setting_definition_id', $definition->id)
                ->lockForUpdate()
                ->first();
            if ($draft === null) {
                throw new SettingException('SETTING_DRAFT_MISSING', '请先保存配置草稿。', 409);
            }
            if ($definition->risk_level === 'high' && $draft->review_status !== 'approved') {
                throw new SettingException('SETTING_REVIEW_REQUIRED', '高风险配置通过二次审核后才能发布。', 409);
            }
            if ($definition->risk_level !== 'high' && $draft->review_status !== 'not_required') {
                throw new SettingException('SETTING_REVIEW_STATE_INVALID', '配置草稿状态不允许发布。', 409);
            }

            $latest = $definition->versions()->lockForUpdate()->latest('version')->first();
            if ($draft->base_version_id !== $latest?->id) {
                throw new SettingException('SETTING_BASE_VERSION_CHANGED', '线上版本已经变化，请重新保存草稿。', 409);
            }
            $version = SystemSettingVersion::query()->create([
                'setting_definition_id' => $definition->id,
                'version' => ($latest?->version ?? 0) + 1,
                'value' => $draft->value,
                'source' => $draft->rollback_source_version_id === null ? 'publish' : 'rollback',
                'source_version_id' => $draft->rollback_source_version_id,
                'reason' => $draft->reason,
                'published_by_admin_id' => $publisher->id,
                'approved_by_admin_id' => $draft->reviewed_by_admin_id,
                'published_at' => now(),
            ]);
            $draft->delete();

            return $version;
        });

        SystemSettingPublished::dispatch($settingKey);
        $this->read($settingKey);

        return $version->fresh(['definition']);
    }

    public function createRollbackDraft(
        string $settingKey,
        int $versionNumber,
        string $reason,
        AdminUser $actor,
        ?int $expectedLockVersion = null,
    ): SystemSettingDraft {
        $definition = $this->definition($settingKey);
        $source = $definition->versions()->where('version', $versionNumber)->first();
        if ($source === null) {
            throw new SettingException('SETTING_VERSION_NOT_FOUND', '指定的历史版本不存在。', 404);
        }

        return $this->saveDraft($settingKey, $source->value, $reason, $actor, $expectedLockVersion, $source);
    }
}
