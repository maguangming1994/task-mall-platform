<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Settings\Models\SystemSettingDraft;
use App\Modules\Settings\Models\SystemSettingVersion;
use App\Modules\Settings\Services\PageDecorationService;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPageDecorationController extends Controller
{
    public function __construct(private readonly PageDecorationService $decoration) {}

    public function index(SystemSettingService $settings): JsonResponse
    {
        $definition = $settings->definition(PageDecorationService::SETTING_KEY);
        $definition->load(['draft', 'versions' => fn ($query) => $query->latest('version')]);
        $current = $definition->versions->first();

        return ApiResponse::success([
            'config' => $this->decoration->published(),
            'current_version' => $current?->version,
            'draft' => $definition->draft === null ? null : $this->presentDraft($definition->draft),
            'versions' => $definition->versions->map(fn (SystemSettingVersion $version) => $this->presentVersion($version))->values(),
        ]);
    }

    public function saveDraft(
        Request $request,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'value' => ['present'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'expected_lock_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = SystemSettingDraft::query()
            ->whereHas('definition', fn ($query) => $query->where('setting_key', PageDecorationService::SETTING_KEY))
            ->first();
        $draft = $settings->saveDraft(
            PageDecorationService::SETTING_KEY,
            $data['value'],
            $data['reason'],
            $admin,
            $data['expected_lock_version'] ?? null,
        );
        $audit->record(
            $admin,
            'page_decoration.save_draft',
            'system_setting',
            PageDecorationService::SETTING_KEY,
            $before === null ? null : $this->presentDraft($before),
            $this->presentDraft($draft),
            $data['reason'],
        );

        return ApiResponse::success($this->presentDraft($draft), 200, '装修草稿已保存，线上页面未受影响。');
    }

    public function publish(
        Request $request,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        $request->validate(['confirm_publish' => ['required', 'accepted']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $definition = $settings->definition(PageDecorationService::SETTING_KEY);
        $before = $definition->versions()->latest('version')->first();
        $version = $settings->publish(PageDecorationService::SETTING_KEY, $admin);
        $audit->record(
            $admin,
            'page_decoration.publish',
            'system_setting',
            PageDecorationService::SETTING_KEY,
            $before === null ? null : $this->presentVersion($before),
            $this->presentVersion($version),
            $version->reason,
        );

        return ApiResponse::success($this->presentVersion($version), 200, '装修配置已发布并刷新缓存。');
    }

    public function versions(SystemSettingService $settings): JsonResponse
    {
        $definition = $settings->definition(PageDecorationService::SETTING_KEY);
        $versions = $definition->versions()->latest('version')->get();

        return ApiResponse::success([
            'items' => $versions->map(fn (SystemSettingVersion $version) => $this->presentVersion($version))->values(),
        ]);
    }

    public function rollback(
        Request $request,
        SystemSettingService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'target_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'expected_lock_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $draft = $settings->createRollbackDraft(
            PageDecorationService::SETTING_KEY,
            $data['target_version'],
            $data['reason'],
            $admin,
            $data['expected_lock_version'] ?? null,
        );
        $audit->record(
            $admin,
            'page_decoration.create_rollback_draft',
            'system_setting',
            PageDecorationService::SETTING_KEY,
            null,
            $this->presentDraft($draft),
            $data['reason'],
        );

        return ApiResponse::success($this->presentDraft($draft), 201, '回滚草稿已创建，发布后生成新版本。');
    }

    /** @return array<string, mixed> */
    private function presentDraft(SystemSettingDraft $draft): array
    {
        return [
            'value' => $draft->value,
            'reason' => $draft->reason,
            'review_status' => $draft->review_status,
            'base_version' => $draft->baseVersion?->version,
            'rollback_source_version' => $draft->rollbackSourceVersion?->version,
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
}
