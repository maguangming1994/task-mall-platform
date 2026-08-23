<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\TaskTemplate;
use App\Modules\Task\Services\TaskDefinitionService;
use App\Modules\Task\Services\TaskTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTaskTemplateController extends Controller
{
    public function index(Request $request, TaskTemplateService $templates): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'category' => ['sometimes', 'string', 'max:64'],
            'keyword' => ['sometimes', 'string', 'max:120'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = TaskTemplate::query()->latest('id');
        $query->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        $query->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category));
        $query->when($filters['keyword'] ?? null, fn ($q, $keyword) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%")));
        $paginator = $query->paginate($filters['page_size'] ?? 20);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (TaskTemplate $template) => $templates->present($template, false))->values(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    public function show(TaskTemplate $taskTemplate, TaskTemplateService $templates): JsonResponse
    {
        return ApiResponse::success($templates->present($taskTemplate));
    }

    public function store(Request $request, TaskTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,63}$/'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'content' => ['required', 'array'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $template = $templates->create($admin, collect($data)->except('content')->all(), $data['content'], $this->key($request));
        $presented = $templates->present($template);
        $audit->record($admin, 'task_template.create', 'task_template', $template->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    public function update(Request $request, TaskTemplate $taskTemplate, TaskTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'content' => ['sometimes', 'array'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $templates->present($taskTemplate);
        $attributes = collect($data)->except(['version', 'content'])->all();
        $changed = $templates->update($admin, $taskTemplate, $attributes, $data['content'] ?? null, $data['version']);
        $after = $templates->present($changed);
        $audit->record($admin, 'task_template.update', 'task_template', $taskTemplate->public_id, $before, $after);

        return ApiResponse::success($after);
    }

    public function copy(Request $request, TaskTemplate $taskTemplate, TaskTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,63}$/'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $copy = $templates->copy($admin, $taskTemplate, $data['code'], $data['name'], $this->key($request));
        $presented = $templates->present($copy);
        $audit->record($admin, 'task_template.copy', 'task_template', $copy->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    public function status(Request $request, TaskTemplate $taskTemplate, TaskTemplateService $templates, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:enable,disable'], 'version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $templates->present($taskTemplate);
        $changed = $templates->changeStatus($admin, $taskTemplate, $data['action'], $data['version']);
        $after = $templates->present($changed);
        $audit->record($admin, 'task_template.'.$data['action'], 'task_template', $taskTemplate->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function versions(TaskTemplate $taskTemplate, TaskTemplateService $templates): JsonResponse
    {
        return ApiResponse::success($templates->versions($taskTemplate));
    }

    public function createTask(Request $request, TaskTemplate $taskTemplate, TaskTemplateService $templates, TaskDefinitionService $tasks, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'overrides' => ['required', 'array'],
            'overrides.reward_budgets' => ['required', 'array', 'min:1'],
            'overrides.reward_budgets.*.reward_sequence' => ['required', 'integer', 'min:1'],
            'overrides.reward_budgets.*.budget_pool_id' => ['required', 'string', 'size:26'],
            'overrides.claim_starts_at' => ['required', 'date'],
            'overrides.claim_ends_at' => ['required', 'date'],
            'overrides.submit_ends_at' => ['required', 'date'],
            'overrides.reward_quota_total' => ['sometimes', 'integer', 'min:1'],
            'overrides.reward_quota_per_cycle' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $task = $templates->createTaskDraft($admin, $taskTemplate, $data['overrides'], $this->key($request));
        $presented = $tasks->present($task);
        $audit->record($admin, 'task_template.create_task_draft', 'task', $task->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, '缺少有效的幂等请求标识。');

        return $key;
    }
}
