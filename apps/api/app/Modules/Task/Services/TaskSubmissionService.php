<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskReview;
use App\Modules\Task\Models\TaskReviewReason;
use App\Modules\Task\Models\TaskStepReview;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Support\TaskClaimState;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskSubmissionService
{
    public function __construct(
        private readonly TaskRewardDeliveryService $rewards,
        private readonly TaskRiskService $risk,
        private readonly ReferralService $referrals,
    ) {}

    public function submit(User $user, TaskClaim $claim, array $content, array $fileIds, string $idempotencyKey): TaskSubmission
    {
        $hash = $this->fingerprint('task-submit:'.$claim->id.':'.$idempotencyKey);
        $existing = TaskSubmission::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $this->validateFiles($user, $fileIds, $claim->task_snapshot['attachment_rules'] ?? []);

        $submission = DB::transaction(function () use ($user, $claim, $content, $fileIds, $hash): TaskSubmission {
            $locked = TaskClaim::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($claim->id);
            if (($locked->task_snapshot['participation_mode'] ?? null) !== 'claim_and_submit') {
                throw ValidationException::withMessages(['claim' => ['该任务无需人工提交材料。']]);
            }
            if (! in_array($locked->status, ['claimed', 'withdrawn', 'rejected'], true)) {
                throw ValidationException::withMessages(['claim' => ['当前任务状态不能提交。']]);
            }
            if ($locked->completion_deadline_at->isPast()) {
                throw ValidationException::withMessages(['claim' => ['任务实际完成期限已过。']]);
            }
            $limit = (int) ($locked->task_snapshot['submission_limit'] ?? 3);
            if ($locked->submission_count >= $limit) {
                throw ValidationException::withMessages(['submission' => ['提交次数已用完，撤回不会返还次数。']]);
            }
            $superseded = null;
            $revisionStepIds = [];
            if ($locked->status === 'rejected') {
                $superseded = TaskSubmission::query()->where('task_claim_id', $locked->id)->where('status', 'rejected')->latest('submission_no')->lockForUpdate()->first();
                $revisionStepIds = array_values(array_unique($superseded?->revision_step_ids ?? []));
                if ($superseded !== null && $revisionStepIds !== []) {
                    [$content, $fileIds] = $this->mergeRevisionPayload($superseded, $content, $fileIds, $revisionStepIds);
                }
            }
            $this->validateFiles($user, $fileIds, $locked->task_snapshot['attachment_rules'] ?? []);
            $this->validateStepSubmission($locked->task_snapshot['steps'] ?? [], $content, $fileIds, $revisionStepIds === [] ? null : $revisionStepIds);
            if (trim((string) ($content['text'] ?? '')) === '' && $fileIds === [] && empty($content['fields'])) {
                throw ValidationException::withMessages(['content' => ['请至少提交文字、表单字段或一个附件。']]);
            }
            $number = $locked->submission_count + 1;
            $reviewHours = (int) ($locked->task_snapshot['review_deadline_hours'] ?? 48);
            $submission = TaskSubmission::query()->create([
                'task_claim_id' => $locked->id, 'user_id' => $user->id, 'submission_no' => $number,
                'content_snapshot' => $content, 'file_public_ids' => array_values($fileIds),
                'revision_step_ids' => $revisionStepIds === [] ? null : $revisionStepIds,
                'supersedes_submission_id' => $superseded?->id,
                'idempotency_key_hash' => $hash, 'submitted_at' => now(), 'review_due_at' => now()->addHours($reviewHours),
            ]);
            $from = $locked->status;
            $locked->update([
                'status' => 'submitted', 'execution_status' => TaskClaimState::EXECUTION_UNDER_REVIEW,
                'review_status' => TaskClaimState::REVIEW_QUEUED, 'submission_count' => $number,
                'progress_started_at' => $locked->progress_started_at ?? now(),
            ]);
            $this->recordChange($locked, $from, 'submitted', 'submit', '用户提交第 '.$number.' 次任务材料。', 'user', $user->id, $hash);

            return $submission->fresh();
        });
        $this->risk->assessSubmission($submission);

        return $submission;
    }

    public function withdraw(User $user, TaskSubmission $submission, string $reason, string $idempotencyKey): TaskSubmission
    {
        $hash = $this->fingerprint('task-withdraw:'.$submission->id.':'.$idempotencyKey);
        if (DB::table('task_claim_status_changes')->where('idempotency_key_hash', $hash)->exists()) {
            return $submission->fresh();
        }

        return DB::transaction(function () use ($user, $submission, $reason, $hash): TaskSubmission {
            $locked = TaskSubmission::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($submission->id);
            if ($locked->status !== 'submitted' || $locked->review_started_at !== null) {
                throw ValidationException::withMessages(['submission' => ['审核开始后不能撤回。']]);
            }
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $locked->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            $claim->update(['status' => 'withdrawn', 'execution_status' => TaskClaimState::EXECUTION_IN_PROGRESS, 'review_status' => TaskClaimState::REVIEW_VOIDED]);
            $this->recordChange($claim, 'submitted', 'withdrawn', 'withdraw_submission', $reason, 'user', $user->id, $hash);

            return $locked->fresh();
        });
    }

    public function review(AdminUser $admin, TaskSubmission $submission, string $decision, ?string $reasonCode, ?string $note, string $idempotencyKey, array $stepResults = []): TaskSubmission
    {
        $hash = $this->fingerprint('task-review:'.$submission->id.':'.$idempotencyKey);
        if (TaskReview::query()->where('idempotency_key_hash', $hash)->exists()) {
            return $submission->fresh();
        }
        if ($decision === 'reject' && $stepResults === [] && (trim((string) $reasonCode) === '' || trim((string) $note) === '')) {
            throw ValidationException::withMessages(['note' => ['驳回必须选择原因并填写说明。']]);
        }

        $reviewed = DB::transaction(function () use ($admin, $submission, $decision, $reasonCode, $note, $hash, $stepResults): TaskSubmission {
            $locked = TaskSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if (! in_array($locked->status, ['submitted', 'reviewing'], true)) {
                throw ValidationException::withMessages(['submission' => ['该提交已撤回或已完成审核。']]);
            }
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $this->claimReviewOwnership($admin, $locked);
            if ($decision === 'approve' && DB::table('task_risk_cases')->where('task_claim_id', $claim->id)->whereIn('status', ['open', 'reviewing'])->where('reward_frozen', true)->exists()) {
                throw ValidationException::withMessages(['risk' => ['该提交存在尚未结案的高风险案件，暂不能通过或发奖。']]);
            }
            if ($locked->status === 'submitted') {
                $locked->update(['status' => 'reviewing', 'review_started_at' => now()]);
                $claim->update(['status' => 'reviewing', 'execution_status' => TaskClaimState::EXECUTION_UNDER_REVIEW, 'review_status' => TaskClaimState::REVIEW_REVIEWING]);
            }
            $normalizedSteps = $this->normalizeStepResults($claim->task_snapshot['steps'] ?? [], $locked->revision_step_ids ?? [], $decision, $reasonCode, $note, $stepResults);
            $failedStepIds = collect($normalizedSteps)->where('decision', 'reject')->pluck('step_id')->values()->all();
            $primaryFailure = collect($normalizedSteps)->firstWhere('decision', 'reject');
            $effectiveReasonCode = $reasonCode ?? ($primaryFailure['reason_code'] ?? null);
            $effectiveNote = $note ?? ($primaryFailure['note'] ?? null);
            $review = TaskReview::query()->create([
                'task_submission_id' => $locked->id, 'task_claim_id' => $claim->id, 'reviewer_admin_id' => $admin->id,
                'decision' => $decision, 'reason_code' => $effectiveReasonCode, 'note' => $effectiveNote,
                'review_snapshot' => ['submission_no' => $locked->submission_no, 'content' => $locked->content_snapshot, 'files' => $locked->file_public_ids, 'step_results' => $normalizedSteps],
                'idempotency_key_hash' => $hash, 'reviewed_at' => now(),
            ]);
            $stepFiles = data_get($locked->content_snapshot, 'fields.step_files', []);
            foreach ($normalizedSteps as $stepResult) {
                TaskStepReview::query()->create([
                    'task_review_id' => $review->id, 'task_submission_id' => $locked->id,
                    'step_id' => $stepResult['step_id'], 'step_title' => $stepResult['step_title'],
                    'decision' => $stepResult['decision'], 'reason_code' => $stepResult['reason_code'], 'note' => $stepResult['note'],
                    'evidence_file_public_ids' => is_array($stepFiles[$stepResult['step_id']] ?? null) ? array_values($stepFiles[$stepResult['step_id']]) : [],
                ]);
            }
            if ($decision === 'approve') {
                $locked->update(['status' => 'approved', 'revision_step_ids' => null, 'review_lock_expires_at' => null]);
                $claim->update(['status' => 'approved', 'execution_status' => TaskClaimState::EXECUTION_COMPLETED, 'review_status' => TaskClaimState::REVIEW_APPROVED, 'slot_status' => 'consumed', 'approved_at' => now()]);
                $this->recordChange($claim, 'reviewing', 'approved', 'approve_submission', $note ?: '任务材料审核通过。', 'admin', $admin->id, $hash);
            } else {
                $locked->update(['status' => 'rejected', 'revision_step_ids' => $failedStepIds, 'review_lock_expires_at' => null]);
                $claim->update(['status' => 'rejected', 'execution_status' => TaskClaimState::EXECUTION_REVISION_REQUIRED, 'review_status' => TaskClaimState::REVIEW_NEEDS_REVISION]);
                $this->recordChange($claim, 'reviewing', 'rejected', 'reject_submission', ($effectiveReasonCode ?: 'other').': '.$effectiveNote, 'admin', $admin->id, $hash);
            }

            return $locked->fresh();
        });

        if ($decision === 'approve') {
            $claim = TaskClaim::query()->findOrFail($reviewed->task_claim_id);
            $this->rewards->deliverForClaim($claim);
            $this->referrals->recordFirstTask($claim->fresh());
        }

        return $reviewed->fresh();
    }

    public function startReview(AdminUser $admin, TaskSubmission $submission, string $idempotencyKey): TaskSubmission
    {
        $hash = $this->fingerprint('task-start-review:'.$submission->id.':'.$idempotencyKey);
        if (DB::table('task_claim_status_changes')->where('idempotency_key_hash', $hash)->exists()) {
            return $submission->fresh();
        }

        return DB::transaction(function () use ($admin, $submission, $hash): TaskSubmission {
            $locked = TaskSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if (! in_array($locked->status, ['submitted', 'reviewing'], true)) {
                throw ValidationException::withMessages(['submission' => ['只有待审核或审核中的提交可以占用。']]);
            }
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $this->claimReviewOwnership($admin, $locked);
            $locked->update(['status' => 'reviewing', 'review_started_at' => $locked->review_started_at ?? now()]);
            $claim->update(['status' => 'reviewing', 'execution_status' => TaskClaimState::EXECUTION_UNDER_REVIEW, 'review_status' => TaskClaimState::REVIEW_REVIEWING]);
            $this->recordChange($claim, 'submitted', 'reviewing', 'start_review', '审核员开始处理任务材料。', 'admin', $admin->id, $hash);

            return $locked->fresh();
        });
    }

    public function releaseReview(AdminUser $admin, TaskSubmission $submission, string $reason, string $idempotencyKey): TaskSubmission
    {
        $hash = $this->fingerprint('task-release-review:'.$submission->id.':'.$idempotencyKey);
        if (DB::table('task_claim_status_changes')->where('idempotency_key_hash', $hash)->exists()) {
            return $submission->fresh();
        }

        return DB::transaction(function () use ($admin, $submission, $reason, $hash): TaskSubmission {
            $locked = TaskSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertReviewOwner($admin, $locked);
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $locked->update(['status' => 'submitted', 'reviewer_admin_id' => null, 'review_assigned_at' => null, 'review_lock_expires_at' => null]);
            $claim->update(['status' => 'submitted', 'execution_status' => TaskClaimState::EXECUTION_UNDER_REVIEW, 'review_status' => TaskClaimState::REVIEW_QUEUED]);
            $this->recordChange($claim, 'reviewing', 'submitted', 'release_review', $reason, 'admin', $admin->id, $hash);

            return $locked->fresh();
        });
    }

    public function transferReview(AdminUser $admin, TaskSubmission $submission, AdminUser $target, string $reason, string $idempotencyKey): TaskSubmission
    {
        if ($target->status !== 'active' || ! $target->hasPermission('task.review')) {
            throw ValidationException::withMessages(['target_admin_id' => ['目标管理员不可用或没有任务审核权限。']]);
        }
        $hash = $this->fingerprint('task-transfer-review:'.$submission->id.':'.$idempotencyKey);
        if (DB::table('task_claim_status_changes')->where('idempotency_key_hash', $hash)->exists()) {
            return $submission->fresh();
        }

        return DB::transaction(function () use ($admin, $submission, $target, $reason, $hash): TaskSubmission {
            $locked = TaskSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $this->assertReviewOwner($admin, $locked);
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $locked->update(['reviewer_admin_id' => $target->id, 'review_assigned_at' => now(), 'review_lock_expires_at' => now()->addMinutes(30)]);
            $this->recordChange($claim, 'reviewing', 'reviewing', 'transfer_review', $reason.'；转派至 '.$target->display_name, 'admin', $admin->id, $hash);

            return $locked->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function present(TaskSubmission $submission): array
    {
        $submission->loadMissing(['claim', 'reviewer', 'supersededSubmission']);
        $latestReview = $submission->reviews()->with('stepReviews')->latest('reviewed_at')->first();

        return [
            'id' => $submission->public_id, 'claim_id' => $submission->claim?->public_id, 'task_claim_id' => $submission->claim?->public_id,
            'submission_no' => $submission->submission_no, 'status' => $submission->status,
            'execution_status' => $submission->claim?->execution_status,
            'execution_status_label' => $submission->claim === null ? null : TaskClaimState::executionLabel($submission->claim->execution_status),
            'review_status' => $submission->claim?->review_status,
            'review_status_label' => $submission->claim === null ? null : TaskClaimState::reviewLabel($submission->claim->review_status),
            'content' => $submission->content_snapshot, 'file_ids' => $submission->file_public_ids ?? [],
            'reviewer' => $submission->reviewer === null ? null : ['id' => $submission->reviewer->public_id, 'display_name' => $submission->reviewer->display_name],
            'review_assigned_at' => $submission->review_assigned_at?->toIso8601String(),
            'review_lock_expires_at' => $submission->review_lock_expires_at?->toIso8601String(),
            'revision_step_ids' => $submission->revision_step_ids ?? [],
            'supersedes_submission_id' => $submission->supersededSubmission?->public_id,
            'latest_review' => $latestReview === null ? null : [
                'decision' => $latestReview->decision, 'reason_code' => $latestReview->reason_code, 'note' => $latestReview->note,
                'reviewed_at' => $latestReview->reviewed_at?->toIso8601String(),
                'step_results' => $latestReview->stepReviews->sortBy('id')->map(fn (TaskStepReview $step) => [
                    'step_id' => $step->step_id, 'step_title' => $step->step_title, 'decision' => $step->decision,
                    'reason_code' => $step->reason_code, 'note' => $step->note, 'file_ids' => $step->evidence_file_public_ids ?? [],
                ])->values()->all(),
            ],
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'review_started_at' => $submission->review_started_at?->toIso8601String(),
            'review_due_at' => $submission->review_due_at?->toIso8601String(),
            'review_overdue' => $submission->review_due_at?->isPast() && in_array($submission->status, ['submitted', 'reviewing'], true),
            'withdrawn_at' => $submission->withdrawn_at?->toIso8601String(),
        ];
    }

    private function validateFiles(User $user, array $fileIds, array $rules): void
    {
        if (count($fileIds) !== count(array_unique($fileIds))) {
            throw ValidationException::withMessages(['file_ids' => ['附件不能重复。']]);
        }
        $maxCount = (int) ($rules['max_count'] ?? 15);
        if (count($fileIds) > $maxCount) {
            throw ValidationException::withMessages(['file_ids' => ['附件数量超过任务限制。']]);
        }
        $files = UploadedFile::query()->whereIn('public_id', $fileIds)->get();
        if ($files->count() !== count($fileIds) || $files->contains(fn (UploadedFile $file): bool => $file->owner_user_id !== $user->id || $file->purpose !== 'task_submission' || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['file_ids' => ['附件必须属于当前用户、用途为任务提交且已通过安全扫描。']]);
        }
        $groups = [
            'image' => $files->filter(fn (UploadedFile $file): bool => str_starts_with((string) $file->detected_mime, 'image/')),
            'video' => $files->filter(fn (UploadedFile $file): bool => str_starts_with((string) $file->detected_mime, 'video/')),
        ];
        $groups['file'] = $files->whereNotIn('id', [...$groups['image']->pluck('id')->all(), ...$groups['video']->pluck('id')->all()]);
        $limits = [
            'image' => [(int) ($rules['max_images'] ?? 9), (int) ($rules['max_image_bytes'] ?? 10 * 1024 * 1024)],
            'video' => [(int) ($rules['max_videos'] ?? 1), (int) ($rules['max_video_bytes'] ?? 100 * 1024 * 1024)],
            'file' => [(int) ($rules['max_files'] ?? 5), (int) ($rules['max_file_bytes'] ?? 20 * 1024 * 1024)],
        ];
        foreach ($groups as $type => $group) {
            [$countLimit, $sizeLimit] = $limits[$type];
            if ($group->count() > $countLimit || $group->contains(fn (UploadedFile $file): bool => (int) ($file->actual_size ?? $file->declared_size) > $sizeLimit)) {
                throw ValidationException::withMessages(['file_ids' => ['附件数量或文件大小超过任务发布时的限制。']]);
            }
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $steps
     * @param  array<string,mixed>  $content
     * @param  array<int,string>  $fileIds
     */
    private function validateStepSubmission(array $steps, array $content, array $fileIds, ?array $onlyStepIds = null): void
    {
        if ($steps === []) {
            return;
        }
        $fields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
        $answers = is_array($fields['steps'] ?? null) ? $fields['steps'] : [];
        $checks = is_array($fields['completed_steps'] ?? null) ? $fields['completed_steps'] : [];
        $stepFiles = is_array($fields['step_files'] ?? null) ? $fields['step_files'] : [];
        $allFiles = array_fill_keys($fileIds, true);

        foreach ($steps as $index => $step) {
            $id = (string) ($step['id'] ?? '');
            if ($onlyStepIds !== null && ! in_array($id, $onlyStepIds, true)) {
                continue;
            }
            if ($onlyStepIds === null && ! ($step['required'] ?? false)) {
                continue;
            }
            $title = (string) ($step['title'] ?? ('第 '.($index + 1).' 步'));
            $inputType = (string) ($step['input_type'] ?? 'none');
            if (in_array($inputType, ['text', 'text_image'], true) && trim((string) ($answers[$id] ?? '')) === '') {
                throw ValidationException::withMessages(['fields' => ["请填写必填步骤：{$title}。"]]);
            }
            if (in_array($inputType, ['image', 'text_image'], true)) {
                $ids = is_array($stepFiles[$id] ?? null) ? array_values(array_unique($stepFiles[$id])) : [];
                $min = max(1, (int) ($step['min_files'] ?? 1));
                $max = max($min, (int) ($step['max_files'] ?? 3));
                if (count($ids) < $min || count($ids) > $max || collect($ids)->contains(fn ($fileId): bool => ! isset($allFiles[$fileId]))) {
                    throw ValidationException::withMessages(['file_ids' => ["步骤“{$title}”需要上传 {$min} 至 {$max} 张有效凭证。"]]);
                }
            }
            if (in_array($inputType, ['none', 'link'], true) && ! ($checks[$id] ?? false)) {
                throw ValidationException::withMessages(['fields' => ["请先确认已完成步骤：{$title}。"]]);
            }
        }
    }

    /** @return array{array<string,mixed>,array<int,string>} */
    private function mergeRevisionPayload(TaskSubmission $previous, array $content, array $fileIds, array $revisionStepIds): array
    {
        $previousContent = $previous->content_snapshot ?? [];
        $previousFields = is_array($previousContent['fields'] ?? null) ? $previousContent['fields'] : [];
        $incomingFields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
        $mergedFields = $previousFields;
        $failedOldFiles = [];

        foreach ($revisionStepIds as $stepId) {
            $failedOldFiles = [...$failedOldFiles, ...((array) data_get($previousFields, 'step_files.'.$stepId, []))];
            foreach (['steps', 'completed_steps', 'step_files'] as $group) {
                unset($mergedFields[$group][$stepId]);
                if (array_key_exists($stepId, (array) ($incomingFields[$group] ?? []))) {
                    $mergedFields[$group][$stepId] = $incomingFields[$group][$stepId];
                }
            }
        }

        $preservedFiles = array_values(array_diff($previous->file_public_ids ?? [], array_unique($failedOldFiles)));
        $mergedContent = ['text' => $content['text'] ?? $previousContent['text'] ?? null, 'fields' => $mergedFields];

        return [$mergedContent, array_values(array_unique([...$preservedFiles, ...$fileIds]))];
    }

    /** @return array<int,array{step_id:string,step_title:string,decision:string,reason_code:?string,note:?string}> */
    private function normalizeStepResults(array $steps, array $revisionStepIds, string $decision, ?string $reasonCode, ?string $note, array $stepResults): array
    {
        $definitions = collect($steps)->keyBy(fn (array $step) => (string) ($step['id'] ?? ''));
        $scope = $revisionStepIds === [] ? $definitions->keys()->filter()->values()->all() : array_values(array_unique($revisionStepIds));
        if ($scope === []) {
            return [];
        }
        if ($stepResults === []) {
            $stepResults = array_map(fn (string $stepId) => ['step_id' => $stepId, 'decision' => $decision, 'reason_code' => $reasonCode, 'note' => $note], $scope);
        }

        $results = [];
        foreach ($stepResults as $result) {
            $stepId = (string) ($result['step_id'] ?? '');
            if (! in_array($stepId, $scope, true) || isset($results[$stepId])) {
                throw ValidationException::withMessages(['step_results' => ['步骤审核结果包含重复或不在本次审核范围内的步骤。']]);
            }
            $stepDecision = (string) ($result['decision'] ?? '');
            if (! in_array($stepDecision, ['approve', 'reject'], true)) {
                throw ValidationException::withMessages(['step_results' => ['每个步骤必须明确选择通过或驳回。']]);
            }
            $stepReason = $result['reason_code'] ?? ($stepDecision === 'reject' ? $reasonCode : null);
            $stepNote = $result['note'] ?? ($stepDecision === 'reject' ? $note : null);
            if ($stepDecision === 'reject' && (trim((string) $stepReason) === '' || trim((string) $stepNote) === '')) {
                throw ValidationException::withMessages(['step_results' => ['驳回步骤必须选择原因并填写补交说明。']]);
            }
            if ($stepReason !== null && ! TaskReviewReason::query()->where('code', $stepReason)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['step_results' => ['选择的驳回原因已停用或不存在。']]);
            }
            $results[$stepId] = [
                'step_id' => $stepId, 'step_title' => (string) ($definitions->get($stepId)['title'] ?? $stepId),
                'decision' => $stepDecision, 'reason_code' => $stepReason, 'note' => $stepNote,
            ];
        }
        if (array_diff($scope, array_keys($results)) !== []) {
            throw ValidationException::withMessages(['step_results' => ['请逐项完成本次审核范围内的全部步骤。']]);
        }
        $hasRejected = collect($results)->contains(fn (array $result) => $result['decision'] === 'reject');
        if (($decision === 'approve' && $hasRejected) || ($decision === 'reject' && ! $hasRejected)) {
            throw ValidationException::withMessages(['decision' => ['整单结论必须与逐步骤审核结果一致。']]);
        }

        return array_values($results);
    }

    private function claimReviewOwnership(AdminUser $admin, TaskSubmission $submission): void
    {
        $activeOtherOwner = $submission->reviewer_admin_id !== null
            && $submission->reviewer_admin_id !== $admin->id
            && $submission->review_lock_expires_at?->isFuture();
        if ($activeOtherOwner) {
            throw ValidationException::withMessages(['submission' => ['该提交已由其他审核员占用，请等待释放或由当前审核员转派。']]);
        }
        $submission->update([
            'reviewer_admin_id' => $admin->id,
            'review_assigned_at' => now(),
            'review_lock_expires_at' => now()->addMinutes(30),
        ]);
    }

    private function assertReviewOwner(AdminUser $admin, TaskSubmission $submission): void
    {
        if ($submission->status !== 'reviewing' || $submission->reviewer_admin_id !== $admin->id) {
            throw ValidationException::withMessages(['submission' => ['只有当前占用该提交的审核员可以执行此操作。']]);
        }
    }

    private function recordChange(TaskClaim $claim, ?string $from, string $to, string $action, string $reason, string $actorType, ?int $actorId, string $hash): void
    {
        $fromStates = $from === null ? ['execution_status' => null, 'review_status' => null] : TaskClaimState::fromLegacy($from);
        $toStates = TaskClaimState::fromLegacy($to);
        DB::table('task_claim_status_changes')->insert([
            'task_claim_id' => $claim->id, 'from_status' => $from, 'to_status' => $to, 'action' => $action,
            'from_execution_status' => $fromStates['execution_status'], 'to_execution_status' => $toStates['execution_status'],
            'from_review_status' => $fromStates['review_status'], 'to_review_status' => $toStates['review_status'],
            'reason' => $reason, 'actor_type' => $actorType, 'actor_id' => $actorId, 'idempotency_key_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
