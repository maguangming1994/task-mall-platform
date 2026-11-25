<?php

namespace App\Modules\Robot\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Robot\Models\RobotForwardConfig;
use App\Modules\Robot\Models\RobotMessageRequest;
use App\Modules\Robot\Services\RobotForwardConfigService;
use App\Modules\Robot\Services\RobotForwardContentService;
use App\Modules\Robot\Services\RobotMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminRobotForwardController extends Controller
{
    public function filterOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);
        $campusId = $data['campus_id'] ?? null;
        $campus = $campusId ? Campus::query()->where('public_id', $campusId)->where('status', 'active')->first() : null;
        if ($campusId && $campus === null) {
            throw ValidationException::withMessages(['campus_id' => '所选学校不存在或已停用，请重新选择。']);
        }
        $schools = Campus::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['public_id', 'name', 'province', 'city'])
            ->map(fn (Campus $item): array => [
                'id' => $item->public_id,
                'name' => $item->name,
                'province' => $item->province,
                'city' => $item->city,
            ])->values();

        return ApiResponse::success([
            'schools' => $schools,
            'categories' => $campus === null ? [] : collect(CommunityPost::CATEGORIES)
                ->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])
                ->values(),
            'task_categories' => collect(RobotForwardContentService::TASK_CATEGORIES)
                ->map(fn (string $name, string $code): array => ['code' => $code, 'name' => $name])
                ->values(),
        ]);
    }

    public function index(Request $request, RobotForwardConfigService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $items = RobotForwardConfig::query()
            ->when(filled($data['name'] ?? null), fn ($query) => $query->where('name', 'like', '%'.trim((string) $data['name']).'%'))
            ->when(array_key_exists('enabled', $data), fn ($query) => $query->where('enabled', (bool) $data['enabled']))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (RobotForwardConfig $config): array => $service->present($config))
            ->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function show(RobotForwardConfig $forward, RobotForwardConfigService $service): JsonResponse
    {
        return ApiResponse::success($service->present($forward));
    }

    public function store(Request $request, RobotForwardConfigService $service, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $data = $service->normalize($this->validateConfig($request));
        $config = RobotForwardConfig::query()->create([
            ...$data,
            'created_by_admin_id' => $admin->id,
            'updated_by_admin_id' => $admin->id,
        ]);
        $presented = $service->present($config);
        $audit->record($admin, 'robot.forward.create', 'robot_forward_config', $config->public_id, null, $presented, '管理员新增机器人转发配置。');

        return ApiResponse::success($presented, 201, '转发配置已创建。');
    }

    public function update(Request $request, RobotForwardConfig $forward, RobotForwardConfigService $service, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($forward);
        $forward->fill($service->normalize($this->validateConfig($request)));
        $forward->updated_by_admin_id = $admin->id;
        $forward->save();
        $presented = $service->present($forward->fresh());
        $audit->record($admin, 'robot.forward.update', 'robot_forward_config', $forward->public_id, $before, $presented, '管理员编辑机器人转发配置。');

        return ApiResponse::success($presented, 200, '转发配置已保存。');
    }

    public function destroy(Request $request, RobotForwardConfig $forward, RobotForwardConfigService $service, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($forward);
        $forward->delete();
        $audit->record($admin, 'robot.forward.delete', 'robot_forward_config', $forward->public_id, $before, null, '管理员删除机器人转发配置。');

        return ApiResponse::success(['id' => $forward->public_id], 200, '转发配置已删除。');
    }

    public function status(Request $request, RobotForwardConfig $forward, RobotForwardConfigService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($forward);
        $forward->forceFill(['enabled' => (bool) $data['enabled'], 'updated_by_admin_id' => $admin->id])->save();
        $presented = $service->present($forward->fresh());
        $audit->record($admin, 'robot.forward.status', 'robot_forward_config', $forward->public_id, $before, $presented, '管理员调整转发配置启停状态。');

        return ApiResponse::success($presented, 200, $forward->enabled ? '转发配置已启用。' : '转发配置已停用。');
    }

    public function batchStatus(Request $request, RobotForwardConfigService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'max:26', 'distinct'],
            'enabled' => ['required', 'boolean'],
        ]);
        $configs = RobotForwardConfig::query()->whereIn('public_id', $data['ids'])->get();
        if ($configs->count() !== count($data['ids'])) {
            throw ValidationException::withMessages(['ids' => '部分转发配置不存在或已删除，请刷新后重试。']);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $configs->map(fn (RobotForwardConfig $config): array => ['id' => $config->public_id, 'enabled' => $config->enabled])->values()->all();
        $items = $configs->map(function (RobotForwardConfig $config) use ($admin, $data, $service): array {
            $config->forceFill(['enabled' => (bool) $data['enabled'], 'updated_by_admin_id' => $admin->id])->save();

            return $service->present($config->fresh());
        })->values();
        $audit->record($admin, 'robot.forward.batch_status', 'robot_forward_config', null, ['items' => $before], [
            'enabled' => (bool) $data['enabled'],
            'ids' => $data['ids'],
        ], '管理员批量调整转发配置启停状态。');

        return ApiResponse::success(['items' => $items], 200, '批量状态调整已完成。');
    }

    public function test(
        Request $request,
        RobotForwardConfig $forward,
        RobotForwardConfigService $service,
        RobotMessageService $messages,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'target_id' => ['required', 'string', 'max:64'],
            'group_id' => ['required', 'string', 'max:191'],
            'post_count' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        $target = collect($forward->robot_targets ?? [])->firstWhere('target_id', $data['target_id']);
        if (! is_array($target)) {
            throw ValidationException::withMessages(['target_id' => '机器人目标不属于当前转发配置。']);
        }
        $group = collect($target['groups'] ?? [])->firstWhere('group_id', $data['group_id']);
        if (! is_array($group)) {
            throw ValidationException::withMessages(['group_id' => '目标群不属于所选机器人目标。']);
        }
        $key = (string) $request->header('Idempotency-Key');
        if (strlen($key) < 12 || strlen($key) > 200) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency-Key 长度必须为 12 至 200 个字符。']);
        }

        /** @var AdminUser $admin */
        $admin = $request->user();
        $existing = RobotMessageRequest::query()
            ->where('created_by_admin_id', $admin->id)
            ->where('idempotency_key_hash', hash('sha256', $key))
            ->first();
        $record = $messages->sendGroupText($admin, [
            'device_id' => $target['device_id'],
            'wxid' => $target['wxid'],
            'group_id' => $group['group_id'],
            'group_name' => $group['group_name'] ?? null,
            'message' => $service->renderTestMessage($forward, (int) ($data['post_count'] ?? 1)),
        ], $key, ['forward_config_id' => $forward->id, 'trigger_type' => 'CONFIG_TEST']);
        $presented = $messages->present($record);
        if ($existing === null) {
            $audit->record($admin, 'robot.forward.test', 'robot_forward_config', $forward->public_id, null, $presented, '管理员使用真实历史帖子测试转发配置。');
        }

        return ApiResponse::success($presented, 201, '测试消息已提交，请根据消息状态确认最终投递结果。');
    }

    /** @return array<string, mixed> */
    private function validateConfig(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'enabled' => ['required', 'boolean'],
            'filter_rules' => ['required', 'array'],
            'filter_rules.platforms' => ['sometimes', 'array', 'max:50'],
            'filter_rules.platforms.*' => ['string', 'max:100', 'distinct'],
            'filter_rules.campus_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'filter_rules.content_type' => ['sometimes', 'nullable', 'string', Rule::in(RobotForwardConfigService::CONTENT_TYPES)],
            'filter_rules.campus_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'filter_rules.categories' => ['sometimes', 'array', 'max:50'],
            'filter_rules.categories.*.code' => ['required', 'string', 'max:100', 'distinct', Rule::in(array_merge(array_keys(CommunityPost::CATEGORIES), array_keys(RobotForwardContentService::TASK_CATEGORIES)))],
            'filter_rules.categories.*.name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter_rules.post_statuses' => ['sometimes', 'array', 'min:1', 'max:5'],
            'filter_rules.post_statuses.*' => ['required', 'string', Rule::in(RobotForwardConfigService::POST_STATUSES)],
            'filter_rules.task_statuses' => ['sometimes', 'array', 'max:10'],
            'filter_rules.task_statuses.*' => ['required', 'string', Rule::in(RobotForwardConfigService::TASK_STATUSES)],
            'filter_rules.aggregate_interval' => ['required', 'integer', 'min:1', 'max:1440'],
            'filter_rules.min_messages' => ['required', 'integer', 'min:1', 'max:1000'],
            'filter_rules.max_messages' => ['required', 'integer', 'min:1', 'max:1000'],
            'filter_rules.quiet_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'filter_rules.quiet_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'filter_rules.active_weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'filter_rules.active_weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'template_config' => ['required', 'array'],
            'template_config.template' => ['required', 'string', 'min:1', 'max:5000'],
            'template_config.footer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'template_config.keywords' => ['sometimes', 'array', 'max:100'],
            'template_config.keywords.*' => ['string', 'max:100', 'distinct'],
            'template_config.content_max_length' => ['required', 'integer', 'min:0', 'max:5000'],
            'robot_targets' => ['required', 'array', 'min:1', 'max:20'],
            'robot_targets.*.target_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'robot_targets.*.device_id' => ['required', 'string', 'max:191'],
            'robot_targets.*.device_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'robot_targets.*.wxid' => ['required', 'string', 'max:191'],
            'robot_targets.*.account_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'robot_targets.*.groups' => ['required', 'array', 'min:1', 'max:100'],
            'robot_targets.*.groups.*.group_id' => ['required', 'string', 'max:191'],
            'robot_targets.*.groups.*.group_name' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        if ((int) $data['filter_rules']['min_messages'] > (int) $data['filter_rules']['max_messages']) {
            throw ValidationException::withMessages(['filter_rules' => '最少消息数不能大于最多消息数。']);
        }
        $campusId = $data['filter_rules']['campus_id'] ?? null;
        $campus = $campusId ? Campus::query()->where('public_id', $campusId)->where('status', 'active')->first() : null;
        if ($campusId && $campus === null) {
            throw ValidationException::withMessages(['filter_rules.campus_id' => '所选学校不存在或已停用，请重新选择。']);
        }
        if ($campus === null && count($data['filter_rules']['categories'] ?? []) > 0) {
            throw ValidationException::withMessages(['filter_rules.categories' => '请先选择学校，再选择该学校论坛的分类。']);
        }
        $data['filter_rules']['campus_name'] = $campus?->name;
        $contentType = ($data['filter_rules']['content_type'] ?? 'community_post') === 'task' ? 'task' : 'community_post';
        $allowedCategoryCodes = $contentType === 'task'
            ? array_keys(RobotForwardContentService::TASK_CATEGORIES)
            : array_keys(CommunityPost::CATEGORIES);
        foreach (($data['filter_rules']['categories'] ?? []) as $idx => $category) {
            if (! in_array((string) ($category['code'] ?? ''), $allowedCategoryCodes, true)) {
                throw ValidationException::withMessages(["filter_rules.categories.$idx.code" => '所选分类与内容类型不匹配，请重新选择。']);
            }
        }

        return $data;
    }
}
