<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Services\RewardBudgetService;
use App\Modules\Task\Services\TaskDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskPublisherTaskController extends Controller
{
    public function budgets(Request $request, RewardBudgetService $budgets): JsonResponse
    {
        $type = $request->validate(['asset_type' => ['sometimes', 'in:points,coupon,wallet_cash']])['asset_type'] ?? null;
        $query = RewardBudgetPool::query()->where('status', 'active');
        if ($type !== null) {
            $query->where('asset_type', $type);
        }

        return ApiResponse::success($query->orderBy('id')->get()->map(fn (RewardBudgetPool $pool) => $budgets->present($pool)));
    }

    public function index(Request $request, TaskDefinitionService $tasks): JsonResponse
    {
        $publisher = $this->publisher($request);
        $items = Task::query()->where('created_by_admin_id', $publisher->admin_user_id)->latest('id')->get()->map(fn (Task $task) => $tasks->present($task));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function store(Request $request, TaskDefinitionService $tasks): JsonResponse
    {
        $publisher = $this->publisher($request);
        $task = $tasks->create($publisher->adminUser, $this->definition($request), $this->key($request), 'user_frontend');

        return ApiResponse::success($tasks->present($task), 201);
    }

    public function show(Request $request, Task $task, TaskDefinitionService $tasks): JsonResponse
    {
        $this->own($request, $task);

        return ApiResponse::success($tasks->present($task));
    }

    public function status(Request $request, Task $task, TaskDefinitionService $tasks): JsonResponse
    {
        $publisher = $this->own($request, $task);
        $data = $request->validate([
            'action' => ['required', 'in:publish,pause,resume,close,archive'], 'reason' => ['required', 'string', 'min:1', 'max:500'],
            'existing_claim_policy' => ['sometimes', 'nullable', 'in:continue,terminate_without_compensation,terminate_with_compensation'],
        ]);
        $changed = $data['action'] === 'publish'
            ? $tasks->publish($publisher->adminUser, $task, $data['reason'], $this->key($request))
            : $tasks->transition($publisher->adminUser, $task, $data['action'], $data['reason'], $this->key($request), $data['existing_claim_policy'] ?? null);

        return ApiResponse::success($tasks->present($changed));
    }

    private function publisher(Request $request): TaskPublisher
    {
        /** @var TaskPublisher $publisher */ $publisher = $request->user();
        $publisher->loadMissing('adminUser');

        return $publisher;
    }

    private function own(Request $request, Task $task): TaskPublisher
    {
        $publisher = $this->publisher($request);
        abort_unless($task->created_by_admin_id === $publisher->admin_user_id, 404);

        return $publisher;
    }

    private function definition(Request $request): array
    {
        $data = $request->validate([
            'business_scenario' => ['sometimes', 'in:platform_task,user_bounty,growth_challenge,referral_milestone'],
            'project_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cover_image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'supported_devices' => ['sometimes', 'array', 'min:1', 'max:2'],
            'supported_devices.*' => ['in:android,ios'],
            'visibility' => ['required', 'in:public,targeted_private'], 'category' => ['required', 'in:onboarding,daily,community,commerce,research,campaign'],
            'task_type' => ['required', 'in:one_time,daily,weekly,repeatable'], 'participation_mode' => ['required', 'in:claim_and_submit,claim_and_auto_verify,auto_enroll'],
            'verification_mode' => ['required', 'in:manual,automatic'], 'title' => ['required', 'string', 'min:1', 'max:160'], 'description' => ['required', 'string', 'min:1', 'max:200000'],
            'tags' => ['sometimes', 'array', 'max:10'], 'tags.*' => ['string', 'min:1', 'max:30'],
            'rewards' => ['required', 'array', 'min:1'], 'rewards.*.type' => ['required', 'in:points,coupon,wallet_balance'],
            'rewards.*.points' => ['sometimes', 'integer', 'min:1'], 'rewards.*.balance_amount' => ['sometimes', 'integer', 'min:1'], 'rewards.*.coupon_template_id' => ['sometimes', 'string', 'size:26'],
            'reward_budgets' => ['required', 'array', 'min:1'], 'reward_budgets.*.reward_sequence' => ['required', 'integer', 'min:1'], 'reward_budgets.*.budget_pool_id' => ['required', 'string', 'size:26'],
            'reward_quota_total' => ['required', 'integer', 'min:1'], 'reward_quota_per_cycle' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'completion_window_hours' => ['sometimes', 'integer', 'min:1'], 'submission_limit' => ['sometimes', 'integer', 'min:1'], 'review_deadline_hours' => ['sometimes', 'integer', 'min:1'],
            'appeal_deadline_days' => ['sometimes', 'integer', 'min:1'], 'appeal_limit' => ['sometimes', 'integer', 'min:0'], 'attachment_rules' => ['sometimes', 'array'],
            'steps' => ['sometimes', 'array', 'max:20'], 'steps.*.id' => ['required', 'string', 'max:40'], 'steps.*.title' => ['required', 'string', 'max:100'],
            'steps.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'steps.*.kind' => ['sometimes', 'in:instruction,link,qr_code,copy_text,image_guide,verification_image,form_input,confirmation'],
            'steps.*.input_type' => ['required', 'in:none,text,image,text_image,link'],
            'steps.*.required' => ['required', 'boolean'], 'steps.*.link_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'steps.*.copy_text' => ['sometimes', 'nullable', 'string', 'max:5000'], 'steps.*.example_image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'steps.*.button_text' => ['sometimes', 'nullable', 'string', 'max:20'], 'steps.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:120'],
            'steps.*.min_files' => ['sometimes', 'integer', 'min:1', 'max:9'], 'steps.*.max_files' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'claim_starts_at' => ['required', 'date'], 'claim_ends_at' => ['required', 'date'], 'submit_ends_at' => ['required', 'date'], 'rules' => ['sometimes', 'array'],
        ]);

        return [
            ...$data,
            'business_scenario' => $data['business_scenario'] ?? 'user_bounty',
            'project_name' => $data['project_name'] ?? null,
            'cover_image_url' => $data['cover_image_url'] ?? null,
            'supported_devices' => $data['supported_devices'] ?? ['android', 'ios'],
            'tags' => $data['tags'] ?? [],
            'completion_window_hours' => $data['completion_window_hours'] ?? 24,
            'submission_limit' => $data['submission_limit'] ?? 3,
            'review_deadline_hours' => $data['review_deadline_hours'] ?? 48,
            'appeal_deadline_days' => $data['appeal_deadline_days'] ?? 7,
            'appeal_limit' => $data['appeal_limit'] ?? 1,
            'rules' => $data['rules'] ?? [],
            'steps' => $data['steps'] ?? [],
        ];
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
