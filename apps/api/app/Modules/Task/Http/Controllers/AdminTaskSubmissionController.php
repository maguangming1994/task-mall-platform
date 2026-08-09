<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\TaskRewardDelivery;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Services\TaskRewardDeliveryService;
use App\Modules\Task\Services\TaskSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTaskSubmissionController extends Controller
{
    public function index(Request $request, TaskSubmissionService $submissions): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:submitted,reviewing,approved,rejected,withdrawn'], 'overdue' => ['sometimes', 'boolean']]);
        $query = TaskSubmission::query()->with('claim')->latest('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($data['overdue'] ?? false) {
            $query->whereIn('status', ['submitted', 'reviewing'])->where('review_due_at', '<=', now());
        }
        $items = $query->get()->map(fn (TaskSubmission $submission) => [
            ...$submissions->present($submission),
            'user_id' => $submission->claim?->user?->public_id,
            'task_title' => $submission->claim?->task_snapshot['title'] ?? null,
            'task_steps' => $submission->claim?->task_snapshot['steps'] ?? [],
            'overdue' => $submission->review_due_at?->isPast() && in_array($submission->status, ['submitted', 'reviewing'], true),
        ]);

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function review(Request $request, TaskSubmission $taskSubmission, TaskSubmissionService $submissions, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'], 'reason_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $submissions->present($taskSubmission);
        $reviewed = $submissions->review($admin, $taskSubmission, $data['decision'], $data['reason_code'] ?? null, $data['note'] ?? null, $this->key($request));
        $after = $submissions->present($reviewed);
        $audit->record($admin, 'task_submission.'.$data['decision'], 'task_submission', $taskSubmission->public_id, $before, $after, $data['note'] ?? null);

        return ApiResponse::success($after);
    }

    public function startReview(Request $request, TaskSubmission $taskSubmission, TaskSubmissionService $submissions, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $submissions->present($taskSubmission);
        $started = $submissions->startReview($admin, $taskSubmission, $this->key($request));
        $after = $submissions->present($started);
        $audit->record($admin, 'task_submission.start_review', 'task_submission', $taskSubmission->public_id, $before, $after);

        return ApiResponse::success($after);
    }

    public function retryReward(Request $request, TaskRewardDelivery $taskRewardDelivery, TaskRewardDeliveryService $rewards, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $taskRewardDelivery->toArray();
        $retried = $rewards->manualRetry($taskRewardDelivery, $admin, $data['reason'], $this->key($request));
        $audit->record($admin, 'task_reward.retry', 'task_reward_delivery', $taskRewardDelivery->public_id, $before, $retried->toArray(), $data['reason']);

        return ApiResponse::success(['id' => $retried->public_id, 'status' => $retried->status, 'attempt_count' => $retried->attempt_count], 202);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
