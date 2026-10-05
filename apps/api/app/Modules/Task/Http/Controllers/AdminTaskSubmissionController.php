<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\TaskReviewReason;
use App\Modules\Task\Models\TaskRewardDelivery;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Services\TaskRewardDeliveryService;
use App\Modules\Task\Services\TaskSubmissionService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminTaskSubmissionController extends Controller
{
    public function rewardDeliveries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,failed,delivered'],
        ]);
        $query = TaskRewardDelivery::query()->with('claim.user')->latest('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $items = $query->get()->map(fn (TaskRewardDelivery $delivery) => [
            'id' => $delivery->public_id,
            'claim_id' => $delivery->claim?->public_id,
            'user_id' => $delivery->claim?->user?->public_id,
            'task_title' => $delivery->claim?->task_snapshot['title'] ?? null,
            'asset_type' => $delivery->asset_type,
            'amount' => $delivery->amount,
            'reward_snapshot' => $delivery->reward_snapshot ?? [],
            'status' => $delivery->status,
            'attempt_count' => $delivery->attempt_count,
            'last_error_code' => $delivery->last_error_code,
            'last_error_message' => $delivery->last_error_message,
            'last_attempted_at' => $delivery->last_attempted_at?->toIso8601String(),
            'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
        ]);

        return ApiResponse::success([
            'items' => $items,
            'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()],
        ]);
    }

    public function index(Request $request, TaskSubmissionService $submissions): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:submitted,reviewing,approved,rejected,withdrawn'],
            'execution_status' => ['sometimes', 'in:reserved,in_progress,ready_to_submit,under_review,revision_required,under_appeal,completed,abandoned,expired,terminated'],
            'review_status' => ['sometimes', 'in:unassigned,queued,reviewing,needs_revision,approved,rejected,voided'],
            'overdue' => ['sometimes', 'boolean'],
        ]);
        $query = TaskSubmission::query()->with(['claim', 'reviewer'])->latest('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['execution_status'])) {
            $query->whereHas('claim', fn ($claim) => $claim->where('execution_status', $data['execution_status']));
        }
        if (isset($data['review_status'])) {
            $query->whereHas('claim', fn ($claim) => $claim->where('review_status', $data['review_status']));
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
        // 驳回原因码必须命中启用中的原因字典，防止脏数据进入审核留痕
        $activeReasonCodes = TaskReviewReason::query()->where('status', 'active')->pluck('code')->all();
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'], 'reason_code' => ['sometimes', 'nullable', 'string', 'max:64', 'in:'.implode(',', $activeReasonCodes)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'step_results' => ['sometimes', 'array', 'max:50'],
            'step_results.*.step_id' => ['required', 'string', 'max:80'],
            'step_results.*.decision' => ['required', 'in:approve,reject'],
            'step_results.*.reason_code' => ['sometimes', 'nullable', 'string', 'max:64', 'in:'.implode(',', $activeReasonCodes)],
            'step_results.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $submissions->present($taskSubmission);
        $reviewed = $submissions->review($admin, $taskSubmission, $data['decision'], $data['reason_code'] ?? null, $data['note'] ?? null, $this->key($request), $data['step_results'] ?? []);
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

    public function releaseReview(Request $request, TaskSubmission $taskSubmission, TaskSubmissionService $submissions, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:2', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $submissions->present($taskSubmission);
        $released = $submissions->releaseReview($admin, $taskSubmission, $data['reason'], $this->key($request));
        $after = $submissions->present($released);
        $audit->record($admin, 'task_submission.release_review', 'task_submission', $taskSubmission->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function transferReview(Request $request, TaskSubmission $taskSubmission, TaskSubmissionService $submissions, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'target_admin_id' => ['required', 'string', 'size:26', 'exists:admin_users,public_id'],
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $target = AdminUser::query()->where('public_id', $data['target_admin_id'])->firstOrFail();
        $before = $submissions->present($taskSubmission);
        $transferred = $submissions->transferReview($admin, $taskSubmission, $target, $data['reason'], $this->key($request));
        $after = $submissions->present($transferred);
        $audit->record($admin, 'task_submission.transfer_review', 'task_submission', $taskSubmission->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function reviewReasons(): JsonResponse
    {
        $items = TaskReviewReason::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get()->map(fn (TaskReviewReason $reason) => [
            'id' => $reason->public_id, 'code' => $reason->code, 'label' => $reason->label,
            'suggested_note' => $reason->suggested_note, 'sort_order' => $reason->sort_order,
        ]);

        $reviewers = AdminUser::query()->where('status', 'active')
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'task.review'))
            ->orderBy('display_name')->get()->map(fn (AdminUser $admin) => [
                'id' => $admin->public_id, 'account' => $admin->account, 'display_name' => $admin->display_name,
            ])->values();

        return ApiResponse::success(['items' => $items, 'reviewers' => $reviewers]);
    }

    public function file(TaskSubmission $taskSubmission, UploadedFile $file): StreamedResponse|JsonResponse
    {
        abort_unless(in_array($file->public_id, $taskSubmission->file_public_ids ?? [], true), 404);
        abort_unless($file->purpose === 'task_submission', 404);
        if ($file->status !== 'clean') {
            return ApiResponse::error('FILE_NOT_AVAILABLE', '凭证通过安全扫描后才可预览。', 409);
        }

        return response()->streamDownload(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $file->original_name, [
            'Content-Type' => $file->detected_mime ?? $file->declared_mime,
            'Cache-Control' => 'private, no-store',
        ]);
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
