<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campus\Models\Campus;
use App\Modules\Settings\Services\PageDecorationService;
use App\Modules\Settings\Services\PageSchemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPageSchemeController extends Controller
{
    public function __construct(private readonly PageSchemeService $schemes) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->schemes->overview());
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'page_key' => ['required', Rule::in(PageDecorationService::PAGES)],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $scheme = $this->schemes->create(trim($data['name']), $data['page_key'], $admin);
        $audit->record($admin, 'page_scheme.create', 'system_setting', PageSchemeService::SETTING_KEY, null, $scheme, '新建页面方案。');

        return ApiResponse::success([
            'scheme' => $scheme,
            'overview' => $this->schemes->overview(),
        ], 201, '页面方案已创建。');
    }

    public function update(Request $request, string $scheme, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'page' => ['required', 'array'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $saved = $this->schemes->update($scheme, trim($data['name']), $data['page'], $admin);
        $audit->record(
            $admin,
            'page_scheme.update',
            'system_setting',
            PageSchemeService::SETTING_KEY,
            null,
            $saved,
            $data['reason'] ?? '保存页面方案。',
        );

        return ApiResponse::success([
            'scheme' => $saved,
            'overview' => $this->schemes->overview(),
        ], 200, '页面方案已保存。');
    }

    public function duplicate(Request $request, string $scheme, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $copy = $this->schemes->duplicate($scheme, $admin);
        $audit->record($admin, 'page_scheme.duplicate', 'system_setting', PageSchemeService::SETTING_KEY, null, $copy, '复制页面方案。');

        return ApiResponse::success([
            'scheme' => $copy,
            'overview' => $this->schemes->overview(),
        ], 201, '页面方案已复制。');
    }

    public function channel(Request $request, string $scheme, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(PageSchemeService::CHANNELS)],
            'enabled' => ['required', 'boolean'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $overview = $this->schemes->setChannel($scheme, $data['channel'], (bool) $data['enabled'], $admin);
        $audit->record(
            $admin,
            'page_scheme.channel',
            'system_setting',
            PageSchemeService::SETTING_KEY,
            null,
            ['scheme_id' => $scheme, 'channel' => $data['channel'], 'enabled' => (bool) $data['enabled']],
            (bool) $data['enabled'] ? '启用页面方案渠道。' : '停用页面方案渠道。',
        );

        return ApiResponse::success($overview, 200, (bool) $data['enabled'] ? '方案已生效。' : '方案已停用。');
    }

    public function destroy(Request $request, string $scheme, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $this->schemes->remove($scheme, $admin);
        $audit->record($admin, 'page_scheme.delete', 'system_setting', PageSchemeService::SETTING_KEY, ['id' => $scheme], null, '删除页面方案。');

        return ApiResponse::success(['id' => $scheme], 200, '页面方案已删除。');
    }

    /**
     * 小票模板的“所属区域”下拉，返回数据库自增 ID 与校区名称。
     */
    public function campuses(): JsonResponse
    {
        $items = Campus::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (Campus $campus): array => ['id' => (int) $campus->id, 'name' => (string) $campus->name])
            ->values()
            ->all();

        return ApiResponse::success(['items' => $items]);
    }
}
