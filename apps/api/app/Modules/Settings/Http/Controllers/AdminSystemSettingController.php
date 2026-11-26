<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Services\SmsProviderSettingsService;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Identity\Services\IdentityVerificationSettingsService;
use App\Modules\Payment\Services\WechatPaymentSettingsService;
use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingDraft;
use App\Modules\Settings\Models\SystemSettingVersion;
use App\Modules\Settings\Services\PageDecorationService;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['sometimes', 'string', 'max:48'],
            'keyword' => ['sometimes', 'string', 'max:100'],
        ]);
        $definitions = SystemSettingDefinition::query()
            ->where('enabled', true)
            ->whereNotIn('setting_key', [...$this->specializedSettingKeys(), PageDecorationService::SETTING_KEY])
            ->when(isset($data['group']), fn ($query) => $query->where('group_code', $data['group']))
            ->when(isset($data['keyword']), function ($query) use ($data): void {
                $keyword = '%'.$data['keyword'].'%';
                $query->where(fn ($nested) => $nested
                    ->where('setting_key', 'like', $keyword)
                    ->orWhere('name', 'like', $keyword));
            })
            ->with(['draft.reviewedBy', 'versions' => fn ($query) => $query->latest('version')])
            ->orderBy('group_code')
            ->orderBy('setting_key')
            ->get();

        return ApiResponse::success([
            'items' => $definitions->map(fn (SystemSettingDefinition $definition) => $this->presentDefinition($definition)),
            'groups' => $definitions->pluck('group_code')->unique()->values(),
        ]);
    }

    public function show(string $settingKey, SystemSettingService $settings): JsonResponse
    {
        if ($response = $this->rejectSpecializedSetting($settingKey)) {
            return $response;
        }
        $definition = $settings->definition($settingKey);
        $definition->load(['draft.reviewedBy', 'versions' => fn ($query) => $query->latest('version')]);

        return ApiResponse::success($this->presentDefinition($definition));
    }

    public function saveDraft(
        Request $request,
        string $settingKey,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if ($response = $this->rejectSpecializedSetting($settingKey)) {
            return $response;
        }
        $data = $request->validate([
            'value' => ['present'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'expected_lock_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = SystemSettingDraft::query()
            ->whereHas('definition', fn ($query) => $query->where('setting_key', $settingKey))
            ->first();
        $draft = $settings->saveDraft(
            $settingKey,
            $data['value'],
            $data['reason'],
            $admin,
            $data['expected_lock_version'] ?? null,
        );
        $audit->record(
            $admin,
            'system_setting.save_draft',
            'system_setting',
            $settingKey,
            $before === null ? null : $this->presentDraft($before),
            $this->presentDraft($draft),
            $data['reason'],
        );

        return ApiResponse::success($this->presentDraft($draft), 200, '配置草稿已保存。');
    }

    public function review(
        Request $request,
        string $settingKey,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if ($response = $this->rejectSpecializedSetting($settingKey)) {
            return $response;
        }
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['required', 'string', 'min:4', 'max:500'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $draft = $settings->review($settingKey, $data['decision'], $data['comment'], $admin);
        $audit->record(
            $admin,
            'system_setting.review_'.$data['decision'],
            'system_setting',
            $settingKey,
            null,
            $this->presentDraft($draft),
            $data['comment'],
        );

        return ApiResponse::success($this->presentDraft($draft), 200, '配置审核结果已记录。');
    }

    public function publish(
        Request $request,
        string $settingKey,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if ($response = $this->rejectSpecializedSetting($settingKey)) {
            return $response;
        }
        $request->validate(['confirm_publish' => ['required', 'accepted']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $definition = $settings->definition($settingKey);
        $before = $definition->versions()->latest('version')->first();
        $version = $settings->publish($settingKey, $admin);
        $audit->record(
            $admin,
            'system_setting.publish',
            'system_setting',
            $settingKey,
            $before === null ? null : $this->presentVersion($before),
            $this->presentVersion($version),
            $version->reason,
        );

        return ApiResponse::success($this->presentVersion($version), 200, '配置版本已发布并刷新缓存。');
    }

    public function versions(string $settingKey, SystemSettingService $settings): JsonResponse
    {
        if ($response = $this->rejectSpecializedSetting($settingKey)) {
            return $response;
        }
        $definition = $settings->definition($settingKey);
        $versions = $definition->versions()->latest('version')->get();

        return ApiResponse::success(['items' => $versions->map(fn (SystemSettingVersion $version) => $this->presentVersion($version))]);
    }

    public function rollback(
        Request $request,
        string $settingKey,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        if ($response = $this->rejectSpecializedSetting($settingKey)) {
            return $response;
        }
        $data = $request->validate([
            'target_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'expected_lock_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $draft = $settings->createRollbackDraft(
            $settingKey,
            $data['target_version'],
            $data['reason'],
            $admin,
            $data['expected_lock_version'] ?? null,
        );
        $audit->record(
            $admin,
            'system_setting.create_rollback_draft',
            'system_setting',
            $settingKey,
            null,
            $this->presentDraft($draft),
            $data['reason'],
        );

        return ApiResponse::success($this->presentDraft($draft), 201, '回滚草稿已创建，发布后生成新版本。');
    }

    /** @return array<string, mixed> */
    private function presentDefinition(SystemSettingDefinition $definition): array
    {
        /** @var SystemSettingVersion|null $current */
        $current = $definition->versions->first();
        $draft = $definition->draft;
        $currentValue = $current?->value ?? $definition->default_value;

        return [
            'key' => $definition->setting_key,
            'group' => $definition->group_code,
            'name' => $definition->name,
            'description' => $definition->description,
            'value_type' => $definition->value_type,
            'unit' => $definition->unit,
            'options' => $definition->options,
            'validation_rules' => $definition->validation_rules,
            'risk_level' => $definition->risk_level,
            'current_value' => $currentValue,
            'current_version' => $current?->version,
            'current_source' => $current === null ? 'default' : 'published',
            'draft' => $draft === null ? null : $this->presentDraft($draft),
            'diff' => $draft === null ? null : [
                'before' => $currentValue,
                'after' => $draft->value,
                'changed' => $currentValue !== $draft->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentDraft(SystemSettingDraft $draft): array
    {
        $draft->loadMissing(['definition', 'baseVersion', 'rollbackSourceVersion', 'reviewedBy']);

        return [
            'value' => $draft->value,
            'reason' => $draft->reason,
            'review_status' => $draft->review_status,
            'base_version' => $draft->baseVersion?->version,
            'rollback_source_version' => $draft->rollbackSourceVersion?->version,
            'reviewed_by' => $draft->reviewedBy?->display_name,
            'review_comment' => $draft->review_comment,
            'reviewed_at' => $draft->reviewed_at?->toIso8601String(),
            'lock_version' => $draft->lock_version,
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentVersion(SystemSettingVersion $version): array
    {
        return [
            'version' => $version->version,
            'value' => $version->value,
            'source' => $version->source,
            'source_version' => $version->sourceVersion?->version,
            'reason' => $version->reason,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    private function rejectSpecializedSetting(string $settingKey): ?JsonResponse
    {
        if ($settingKey === PageDecorationService::SETTING_KEY) {
            return ApiResponse::error(
                'SETTING_SPECIALIZED_ENDPOINT',
                '页面装修配置请通过装修中心管理。',
                409,
            );
        }
        if (! in_array($settingKey, $this->specializedSettingKeys(), true)) {
            return null;
        }

        return ApiResponse::error(
            'SETTING_SPECIALIZED_ENDPOINT',
            '短信服务配置必须通过专用安全接口管理。',
            409,
        );
    }

    /** @return list<string> */
    private function specializedSettingKeys(): array
    {
        return [
            SmsProviderSettingsService::SETTING_KEY,
            WechatProviderSettingsService::MINI_SETTING_KEY,
            WechatProviderSettingsService::OA_SETTING_KEY,
            WechatPaymentSettingsService::SETTING_KEY,
            IdentityVerificationSettingsService::SETTING_KEY,
        ];
    }
}
