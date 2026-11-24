<?php

namespace App\Modules\Robot\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Robot\Models\RobotAssistant;
use App\Modules\Robot\Services\RobotAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRobotAssistantController extends Controller
{
    public function index(Request $request, RobotAssistantService $service): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'app_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $items = RobotAssistant::query()
            ->when(filled($data['name'] ?? null), fn ($query) => $query->where('name', 'like', '%'.trim((string) $data['name']).'%'))
            ->when(filled($data['app_id'] ?? null), fn ($query) => $query->where('app_id', 'like', '%'.trim((string) $data['app_id']).'%'))
            ->when(array_key_exists('enabled', $data), fn ($query) => $query->where('enabled', (bool) $data['enabled']))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (RobotAssistant $assistant): array => $service->present($assistant))
            ->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function show(RobotAssistant $assistant, RobotAssistantService $service): JsonResponse
    {
        return ApiResponse::success($service->present($assistant));
    }

    public function store(Request $request, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $created = $service->create($admin, $data);
        $assistant = $created['assistant'];
        $presented = $service->present($assistant);
        $audit->record($admin, 'robot.assistant.create', 'robot_assistant', $assistant->public_id, null, $presented, '管理员新增机器人助手并生成应用凭证。');

        return ApiResponse::success([...$presented, 'app_secret_once' => $created['secret']], 201, '助手已创建，请立即保存仅本次显示的应用密钥。');
    }

    public function update(Request $request, RobotAssistant $assistant, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($assistant);
        $assistant->forceFill([
            'name' => trim((string) $data['name']),
            'remark' => filled($data['remark'] ?? null) ? trim((string) $data['remark']) : null,
            'updated_by_admin_id' => $admin->id,
        ])->save();
        $presented = $service->present($assistant->fresh());
        $audit->record($admin, 'robot.assistant.update', 'robot_assistant', $assistant->public_id, $before, $presented, '管理员编辑机器人助手。');

        return ApiResponse::success($presented, 200, '助手配置已保存。');
    }

    public function destroy(Request $request, RobotAssistant $assistant, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($assistant);
        $assistant->delete();
        $audit->record($admin, 'robot.assistant.delete', 'robot_assistant', $assistant->public_id, $before, null, '管理员删除机器人助手。');

        return ApiResponse::success(['id' => $assistant->public_id], 200, '助手配置已删除。');
    }

    public function status(Request $request, RobotAssistant $assistant, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($assistant);
        $assistant->forceFill(['enabled' => (bool) $data['enabled'], 'updated_by_admin_id' => $admin->id])->save();
        $presented = $service->present($assistant->fresh());
        $audit->record($admin, 'robot.assistant.status', 'robot_assistant', $assistant->public_id, $before, $presented, '管理员调整机器人助手启停状态。');

        return ApiResponse::success($presented, 200, $assistant->enabled ? '助手已启用。' : '助手已停用。');
    }

    public function bind(Request $request, RobotAssistant $assistant, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'wxid' => ['required', 'string', 'max:191'],
            'account_name' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($assistant);
        $assistant = $service->addBinding($assistant, $data);
        $assistant->updated_by_admin_id = $admin->id;
        $assistant->save();
        $presented = $service->present($assistant);
        $audit->record($admin, 'robot.assistant.bind', 'robot_assistant', $assistant->public_id, $before, $presented, '管理员绑定助手机器人。');

        return ApiResponse::success($presented, 201, '机器人绑定成功。');
    }

    public function unbind(Request $request, RobotAssistant $assistant, string $bindingId, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($assistant);
        $assistant = $service->removeBinding($assistant, $bindingId);
        $assistant->updated_by_admin_id = $admin->id;
        $assistant->save();
        $presented = $service->present($assistant);
        $audit->record($admin, 'robot.assistant.unbind', 'robot_assistant', $assistant->public_id, $before, $presented, '管理员解绑助手机器人。');

        return ApiResponse::success($presented, 200, '机器人已解绑。');
    }

    public function webhook(RobotAssistant $assistant, RobotAssistantService $service): JsonResponse
    {
        return ApiResponse::success($service->webhook($assistant));
    }

    public function testWebhook(Request $request, RobotAssistant $assistant, RobotAssistantService $service, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->webhook($assistant);
        $after = $service->selfTest($assistant);
        $audit->record($admin, 'robot.assistant.webhook.self_test', 'robot_assistant', $assistant->public_id, $before, $after, '管理员执行助手 Webhook 本地配置自检。');

        return ApiResponse::success($after, 200, 'Webhook 本地配置自检已完成。');
    }
}
