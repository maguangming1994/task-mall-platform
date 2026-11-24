<?php

namespace App\Modules\Robot\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Robot\Exceptions\XpRobotException;
use App\Modules\Robot\Models\RobotMessageRequest;
use App\Modules\Robot\Services\RobotMessageService;
use App\Modules\Robot\Services\XpRobotClient;
use App\Modules\Robot\Services\XpRobotSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminRobotController extends Controller
{
    public function settings(XpRobotSettingsService $settings): JsonResponse
    {
        return ApiResponse::success($settings->snapshot());
    }

    public function updateSettings(
        Request $request,
        XpRobotSettingsService $settings,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);
        $clear = (bool) ($data['clear_api_key'] ?? false);
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($clear && $apiKey !== '') {
            throw ValidationException::withMessages(['api_key' => '不能同时输入新密钥并勾选清除。']);
        }
        if (! $clear && $apiKey === '') {
            throw ValidationException::withMessages(['api_key' => '请输入新的 XP API Key，或明确选择清除后台密钥。']);
        }

        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $settings->snapshot();
        $after = $settings->update($apiKey !== '' ? $apiKey : null, $clear);
        $audit->record($admin, 'robot.xp.settings.update', 'robot_xp', 'xp', $before, $after, $data['reason']);

        return ApiResponse::success($after, 200, 'XP 机器人配置已更新。');
    }

    public function testSettings(
        Request $request,
        XpRobotSettingsService $settings,
        XpRobotClient $client,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        try {
            $result = $settings->test($client);
            $audit->record($admin, 'robot.xp.settings.test', 'robot_xp', 'xp', null, [
                'accepted' => true,
                'device_count' => $result['device_count'],
                'online_count' => $result['online_count'],
            ], '管理员发起 XP 连接测试。');
        } catch (XpRobotException $exception) {
            $audit->record($admin, 'robot.xp.settings.test_failed', 'robot_xp', 'xp', null, ['accepted' => false], 'XP 连接测试失败。');

            return $this->xpFailure($exception);
        }

        return ApiResponse::success($result, 200, 'XP 连接测试完成。');
    }

    public function devices(Request $request, XpRobotClient $client): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'nullable', 'in:enabled,disabled']]);
        try {
            return ApiResponse::success($client->devices($data['status'] ?? null));
        } catch (XpRobotException $exception) {
            return $this->xpFailure($exception);
        }
    }

    public function accounts(Request $request, XpRobotClient $client): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'string', 'max:191']]);
        try {
            return ApiResponse::success($client->accounts($data['device_id']));
        } catch (XpRobotException $exception) {
            return $this->xpFailure($exception);
        }
    }

    public function groups(Request $request, XpRobotClient $client): JsonResponse
    {
        $rawForce = $request->input('force_realtime');
        if ($rawForce !== null && ! in_array($rawForce, [true, false, 1, 0, '1', '0', 'true', 'false'], true)) {
            throw ValidationException::withMessages([
                'force_realtime' => '强制实时同步参数只能是 true/false 或 1/0。',
            ]);
        }
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
            'wxid' => ['required', 'string', 'max:191'],
        ]);
        try {
            return ApiResponse::success($client->groups(
                $data['device_id'],
                $data['wxid'],
                filter_var($rawForce ?? false, FILTER_VALIDATE_BOOLEAN),
            ));
        } catch (XpRobotException $exception) {
            return $this->xpFailure($exception);
        }
    }

    public function messages(RobotMessageService $messages): JsonResponse
    {
        $recent = RobotMessageRequest::query()->latest('id')->limit(100)->get();
        $batchIds = $recent->pluck('batch_id')->filter()->unique()->values();
        $siblings = $batchIds->isEmpty()
            ? collect()
            : RobotMessageRequest::query()->whereIn('batch_id', $batchIds)->get();
        $items = $recent->concat($siblings)->unique('id')->sortByDesc('id')->values()
            ->map(fn (RobotMessageRequest $record): array => $messages->present($record))
            ->values();

        return ApiResponse::success(['items' => $items]);
    }

    public function sendGroupText(
        Request $request,
        RobotMessageService $messages,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
            'wxid' => ['required', 'string', 'max:191'],
            'group_id' => ['required', 'string', 'max:191'],
            'group_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'message' => ['required', 'string', 'min:1', 'max:5000'],
        ]);
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
        $record = $messages->sendGroupText($admin, $data, $key);
        $presented = $messages->present($record);
        if ($existing === null) {
            $audit->record($admin, 'robot.message.group_text.submit', 'robot_message', $record->public_id, null, $presented, '管理员提交 XP 群文本消息。');
        }

        return ApiResponse::success($presented, 201, '消息请求已保存，请根据本地状态刷新最终投递结果。');
    }

    public function sendMessage(
        Request $request,
        RobotMessageService $messages,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate($this->messageTargetRules());
        $item = $this->validateMessageItem($request->all());
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
        $payload = array_merge($data, $item);
        $scheduled = filled($data['scheduled_at'] ?? null);
        $record = $scheduled
            ? $messages->schedule($admin, $payload, $key)
            : $messages->send($admin, $payload, $key);
        $presented = $messages->present($record);

        if ($existing === null) {
            $audit->record(
                $admin,
                $scheduled ? 'robot.message.schedule' : 'robot.message.submit',
                'robot_message',
                $record->public_id,
                null,
                $presented,
                $scheduled ? '管理员创建 XP 定时发送计划。' : '管理员提交 XP 群消息。',
            );
        }

        return ApiResponse::success($presented, 201, $scheduled
            ? '定时发送计划已保存，到点将自动派发。'
            : '消息请求已保存，请根据本地状态刷新最终投递结果。');
    }

    public function sendMessageBatch(
        Request $request,
        RobotMessageService $messages,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate(array_merge($this->messageTargetRules(), [
            'items' => ['required', 'array', 'list', 'min:2', 'max:10'],
            'items.*' => ['required', 'array'],
        ]));
        $items = collect($data['items'])
            ->map(fn (array $item, int $index): array => $this->validateMessageItem($item, 'items.'.$index.'.'))
            ->values()->all();
        unset($data['items']);
        $key = (string) $request->header('Idempotency-Key');
        if (strlen($key) < 12 || strlen($key) > 200) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency-Key 长度必须为 12 至 200 个字符。']);
        }

        /** @var AdminUser $admin */
        $admin = $request->user();
        $batchId = $messages->batchId($admin, $key);
        $existing = RobotMessageRequest::query()->where('batch_id', $batchId)->exists();
        $records = $messages->sendBatch($admin, array_merge($data, ['items' => $items]), $key);
        $presented = $messages->presentBatch($records);
        $scheduled = filled($data['scheduled_at'] ?? null);

        if (! $existing) {
            $audit->record(
                $admin,
                $scheduled ? 'robot.message_batch.schedule' : 'robot.message_batch.submit',
                'robot_message_batch',
                $batchId,
                null,
                $presented,
                $scheduled ? '管理员创建 XP 组合定时发送计划。' : '管理员提交 XP 组合群消息。',
            );
        }

        return ApiResponse::success($presented, 201, $scheduled
            ? '组合定时发送计划已保存，到点将按顺序自动派发。'
            : '组合消息已保存，正在按顺序提交 XP，请根据各子消息状态查看结果。');
    }

    public function cancelMessage(
        Request $request,
        RobotMessageRequest $message,
        RobotMessageService $messages,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $messages->present($message);
        $record = $messages->cancel($message);
        $after = $messages->present($record);
        $audit->record($admin, 'robot.message.cancel', 'robot_message', $record->public_id, $before, $after, '管理员取消 XP 定时发送计划。');

        return ApiResponse::success($after, 200, '定时发送计划已取消。');
    }

    public function cancelMessageBatch(
        Request $request,
        string $batchId,
        RobotMessageService $messages,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $messages->presentBatch(RobotMessageRequest::query()
            ->where('batch_id', $batchId)->orderBy('batch_sequence')->get());
        $records = $messages->cancelBatch($batchId);
        $after = $messages->presentBatch($records);
        $audit->record(
            $admin,
            'robot.message_batch.cancel',
            'robot_message_batch',
            $batchId,
            $before,
            $after,
            '管理员取消 XP 组合定时发送计划。',
        );

        return ApiResponse::success($after, 200, '组合定时发送计划已整组取消。');
    }

    public function refreshMessage(RobotMessageRequest $message, RobotMessageService $messages): JsonResponse
    {
        $record = $messages->refresh($message);

        return ApiResponse::success($messages->present($record), 200, '消息状态已刷新。');
    }

    /** @return array<string, array<int, string>> */
    private function messageTargetRules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:191'],
            'wxid' => ['required', 'string', 'max:191'],
            'group_id' => ['required', 'string', 'max:191'],
            'group_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /** 校验并规范化一条子消息，批量错误保留 items.{index} 前缀。 */
    private function validateMessageItem(array $input, string $prefix = ''): array
    {
        $validator = Validator::make($input, [
            'msg_type' => ['required', 'string', 'in:text,image,link'],
            'message' => ['required_if:msg_type,text,image', 'nullable', 'string', 'min:1', 'max:5000'],
            'link_url' => ['required_if:msg_type,link', 'nullable', 'string', 'max:2000'],
            'title' => ['required_if:msg_type,link', 'nullable', 'string', 'max:200'],
            'desc' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors[$prefix.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $data = $validator->validated();
        $type = $data['msg_type'];
        $isHttpUrl = static fn (mixed $value): bool => is_string($value) && (bool) preg_match('#^https?://#i', $value);
        if ($type === 'image' && ! $isHttpUrl($data['message'] ?? null)) {
            throw ValidationException::withMessages([$prefix.'message' => '图片消息必须是 http(s) 开头的公网 URL。']);
        }
        foreach (['link_url', 'image_url'] as $urlField) {
            if (filled($data[$urlField] ?? null) && ! $isHttpUrl($data[$urlField])) {
                throw ValidationException::withMessages([$prefix.$urlField => '必须是 http(s) 开头的 URL。']);
            }
        }

        return [
            'msg_type' => $type,
            'message' => in_array($type, ['text', 'image'], true) ? $data['message'] : null,
            'payload' => $type === 'link' ? array_filter([
                'link_url' => $data['link_url'] ?? null,
                'title' => $data['title'] ?? null,
                'desc' => $data['desc'] ?? null,
                'image_url' => $data['image_url'] ?? null,
            ], static fn (mixed $value): bool => filled($value)) : null,
        ];
    }

    private function xpFailure(XpRobotException $exception): JsonResponse
    {
        return ApiResponse::error(
            $exception->indeterminate() ? 'XP_ROBOT_UNAVAILABLE' : 'XP_ROBOT_REQUEST_FAILED',
            $exception->getMessage(),
            $exception->indeterminate() ? 503 : 422,
        );
    }
}
