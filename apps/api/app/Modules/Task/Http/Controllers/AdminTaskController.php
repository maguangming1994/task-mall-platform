<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Services\TaskDataBatchService;
use App\Modules\Task\Services\TaskDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTaskController extends Controller
{
    public function index(Request $request, TaskDefinitionService $tasks): JsonResponse
    {
        $items = Task::query()->latest('id')->get()->map(fn (Task $task) => $tasks->present($task));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function show(Task $task, TaskDefinitionService $tasks): JsonResponse
    {
        return ApiResponse::success($tasks->present($task));
    }

    public function store(Request $request, TaskDefinitionService $tasks, AuditLogger $audit, SystemSettingService $settings, TaskDataBatchService $batches): JsonResponse
    {
        $content = $this->definition($request, $settings, $batches);
        /** @var AdminUser $admin */ $admin = $request->user();
        $task = $tasks->create($admin, $content, $this->key($request));
        $presented = $tasks->present($task);
        $audit->record($admin, 'task.create_draft', 'task', $task->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    public function update(Request $request, Task $task, TaskDefinitionService $tasks, AuditLogger $audit, SystemSettingService $settings, TaskDataBatchService $batches): JsonResponse
    {
        $content = $this->definition($request, $settings, $batches);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $tasks->present($task);
        $after = $tasks->present($tasks->update($admin, $task, $content, $version));
        $audit->record($admin, 'task.create_version', 'task', $task->public_id, $before, $after);

        return ApiResponse::success($after);
    }

    public function versions(Task $task, TaskDefinitionService $tasks): JsonResponse
    {
        return ApiResponse::success($tasks->versionSummaries($task));
    }

    public function rollback(Request $request, Task $task, int $versionNo, TaskDefinitionService $tasks, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'current_version' => ['required', 'integer', 'min:1']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $tasks->rollback($admin, $task, $versionNo, $data['current_version']);
        $audit->record($admin, 'task.rollback_version', 'task', $task->public_id, null, $tasks->present($result), $data['reason']);

        return ApiResponse::success($tasks->present($result), 201);
    }

    public function status(Request $request, Task $task, TaskDefinitionService $tasks, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,pause,resume,close,archive'], 'reason' => ['required', 'string', 'min:1', 'max:500'],
            'existing_claim_policy' => ['sometimes', 'nullable', 'in:continue,terminate_without_compensation,terminate_with_compensation'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $tasks->present($task);
        $changed = $data['action'] === 'publish'
            ? $tasks->publish($admin, $task, $data['reason'], $this->key($request))
            : $tasks->transition($admin, $task, $data['action'], $data['reason'], $this->key($request), $data['existing_claim_policy'] ?? null);
        $after = $tasks->present($changed);
        $audit->record($admin, 'task.'.$data['action'], 'task', $task->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function reviewPublishApproval(Request $request, Task $task, TaskDefinitionService $tasks, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $tasks->present($task);
        $changed = $tasks->reviewPublishApproval($admin, $task, $data['decision'], $data['note'], $this->key($request));
        $after = $tasks->present($changed);
        $audit->record($admin, 'task.publish_approval.'.$data['decision'], 'task', $task->public_id, $before, $after, $data['note']);

        return ApiResponse::success($after);
    }

    /** @return array<string,mixed> */
    private function definition(Request $request, SystemSettingService $settings, TaskDataBatchService $batches): array
    {
        $data = $request->validate([
            'business_scenario' => ['sometimes', 'in:platform_task,user_bounty,growth_challenge,referral_milestone'],
            'project_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cover_image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'supported_devices' => ['sometimes', 'array', 'min:1', 'max:2'],
            'supported_devices.*' => ['in:android,ios'],
            'visibility' => ['required', 'in:public,targeted_private'], 'category' => ['required', 'in:onboarding,daily,community,commerce,research,campaign'],
            'task_type' => ['required', 'in:one_time,daily,weekly,monthly,milestone,repeatable'], 'participation_mode' => ['required', 'in:claim_and_submit,claim_and_auto_verify,auto_enroll'],
            'progress_target' => ['sometimes', 'integer', 'min:1'], 'progress_event_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'verification_mode' => ['required', 'in:manual,automatic'], 'title' => ['required', 'string', 'min:1', 'max:160'], 'description' => ['required', 'string', 'min:1', 'max:200000'],
            'tags' => ['sometimes', 'array', 'max:10'], 'tags.*' => ['string', 'min:1', 'max:30'],
            'rewards' => ['required', 'array', 'min:1'], 'rewards.*.type' => ['required', 'in:points,coupon,wallet_balance'],
            'rewards.*.points' => ['sometimes', 'integer', 'min:1'], 'rewards.*.balance_amount' => ['sometimes', 'integer', 'min:1'],
            'rewards.*.coupon_template_id' => ['sometimes', 'string', 'size:26'],
            'rewards.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'reward_budgets' => ['required', 'array', 'min:1'], 'reward_budgets.*.reward_sequence' => ['required', 'integer', 'min:1'],
            'reward_budgets.*.budget_pool_id' => ['required', 'string', 'size:26'], 'reward_quota_total' => ['required', 'integer', 'min:1'],
            'reward_quota_per_cycle' => ['sometimes', 'nullable', 'integer', 'min:1'], 'completion_window_hours' => ['sometimes', 'integer', 'min:1'],
            'submission_limit' => ['sometimes', 'integer', 'min:1'], 'review_deadline_hours' => ['sometimes', 'integer', 'min:1'],
            'appeal_deadline_days' => ['sometimes', 'integer', 'min:1'], 'appeal_limit' => ['sometimes', 'integer', 'min:0'],
            'virtual_view_count' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'attachment_rules' => ['sometimes', 'array'],
            'steps' => ['sometimes', 'array', 'max:20'], 'steps.*.id' => ['required', 'string', 'max:40'], 'steps.*.title' => ['required', 'string', 'max:100'],
            'steps.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'steps.*.kind' => ['sometimes', 'in:instruction,link,qr_code,copy_text,image_guide,verification_image,form_input,confirmation'],
            'steps.*.input_type' => ['required', 'in:none,text,image,text_image,link'],
            'steps.*.required' => ['required', 'boolean'], 'steps.*.link_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'steps.*.copy_text' => ['sometimes', 'nullable', 'string', 'max:5000'], 'steps.*.example_image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'steps.*.button_text' => ['sometimes', 'nullable', 'string', 'max:20'], 'steps.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:120'],
            'steps.*.min_files' => ['sometimes', 'integer', 'min:1', 'max:9'], 'steps.*.max_files' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'claim_starts_at' => ['required', 'date'], 'claim_ends_at' => ['required', 'date'],
            'submit_ends_at' => ['required', 'date'], 'rules' => ['sometimes', 'array'],
            'data_batch_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $content = [
            ...$data,
            'business_scenario' => $data['business_scenario'] ?? 'platform_task',
            'project_name' => $data['project_name'] ?? null,
            'cover_image_url' => $data['cover_image_url'] ?? null,
            'supported_devices' => $data['supported_devices'] ?? ['android', 'ios'],
            'tags' => $data['tags'] ?? [],
            'completion_window_hours' => $data['completion_window_hours'] ?? $this->integerSetting($settings, 'tasks.default_completion_window_hours', 24),
            'submission_limit' => $data['submission_limit'] ?? $this->integerSetting($settings, 'tasks.default_submission_limit', 3),
            'review_deadline_hours' => $data['review_deadline_hours'] ?? $this->integerSetting($settings, 'tasks.default_review_hours', 48),
            'appeal_deadline_days' => $data['appeal_deadline_days'] ?? $this->integerSetting($settings, 'tasks.default_appeal_days', 7),
            'appeal_limit' => $data['appeal_limit'] ?? $this->integerSetting($settings, 'tasks.default_appeal_limit', 1),
            'virtual_view_count' => $data['virtual_view_count'] ?? 0,
            'progress_target' => $data['progress_target'] ?? 1,
            'progress_event_type' => $data['progress_event_type'] ?? null,
            'rules' => $data['rules'] ?? [],
            'steps' => $data['steps'] ?? [],
        ];
        unset($content['data_batch_id']);
        if (! empty($data['data_batch_id'])) {
            $content['data_batch'] = $batches->reference($data['data_batch_id']);
        }

        return $content;
    }

    private function integerSetting(SystemSettingService $settings, string $key, int $legacyFallback): int
    {
        try {
            return (int) $settings->read($key)['value'];
        } catch (SettingException) {
            return $legacyFallback;
        }
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
