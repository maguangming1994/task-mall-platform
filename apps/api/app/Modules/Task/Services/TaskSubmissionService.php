<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskReview;
use App\Modules\Task\Models\TaskSubmission;
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
            $this->validateStepSubmission($locked->task_snapshot['steps'] ?? [], $content, $fileIds);
            if (trim((string) ($content['text'] ?? '')) === '' && $fileIds === [] && empty($content['fields'])) {
                throw ValidationException::withMessages(['content' => ['请至少提交文字、表单字段或一个附件。']]);
            }
            $number = $locked->submission_count + 1;
            $reviewHours = (int) ($locked->task_snapshot['review_deadline_hours'] ?? 48);
            $submission = TaskSubmission::query()->create([
                'task_claim_id' => $locked->id, 'user_id' => $user->id, 'submission_no' => $number,
                'content_snapshot' => $content, 'file_public_ids' => array_values($fileIds),
                'idempotency_key_hash' => $hash, 'submitted_at' => now(), 'review_due_at' => now()->addHours($reviewHours),
            ]);
            $from = $locked->status;
            $locked->update(['status' => 'submitted', 'submission_count' => $number, 'progress_started_at' => $locked->progress_started_at ?? now()]);
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
            $claim->update(['status' => 'withdrawn']);
            $this->recordChange($claim, 'submitted', 'withdrawn', 'withdraw_submission', $reason, 'user', $user->id, $hash);

            return $locked->fresh();
        });
    }

    public function review(AdminUser $admin, TaskSubmission $submission, string $decision, ?string $reasonCode, ?string $note, string $idempotencyKey): TaskSubmission
    {
        $hash = $this->fingerprint('task-review:'.$submission->id.':'.$idempotencyKey);
        if (TaskReview::query()->where('idempotency_key_hash', $hash)->exists()) {
            return $submission->fresh();
        }
        if ($decision === 'reject' && (trim((string) $reasonCode) === '' || trim((string) $note) === '')) {
            throw ValidationException::withMessages(['note' => ['驳回必须选择原因并填写说明。']]);
        }

        $reviewed = DB::transaction(function () use ($admin, $submission, $decision, $reasonCode, $note, $hash): TaskSubmission {
            $locked = TaskSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if (! in_array($locked->status, ['submitted', 'reviewing'], true)) {
                throw ValidationException::withMessages(['submission' => ['该提交已撤回或已完成审核。']]);
            }
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            if ($decision === 'approve' && DB::table('task_risk_cases')->where('task_claim_id', $claim->id)->whereIn('status', ['open', 'reviewing'])->where('reward_frozen', true)->exists()) {
                throw ValidationException::withMessages(['risk' => ['该提交存在尚未结案的高风险案件，暂不能通过或发奖。']]);
            }
            if ($locked->status === 'submitted') {
                $locked->update(['status' => 'reviewing', 'review_started_at' => now()]);
                $claim->update(['status' => 'reviewing']);
            }
            TaskReview::query()->create([
                'task_submission_id' => $locked->id, 'task_claim_id' => $claim->id, 'reviewer_admin_id' => $admin->id,
                'decision' => $decision, 'reason_code' => $reasonCode, 'note' => $note,
                'review_snapshot' => ['submission_no' => $locked->submission_no, 'content' => $locked->content_snapshot, 'files' => $locked->file_public_ids],
                'idempotency_key_hash' => $hash, 'reviewed_at' => now(),
            ]);
            if ($decision === 'approve') {
                $locked->update(['status' => 'approved']);
                $claim->update(['status' => 'approved', 'slot_status' => 'consumed', 'approved_at' => now()]);
                $this->recordChange($claim, 'reviewing', 'approved', 'approve_submission', $note ?: '任务材料审核通过。', 'admin', $admin->id, $hash);
            } else {
                $locked->update(['status' => 'rejected']);
                $claim->update(['status' => 'rejected']);
                $this->recordChange($claim, 'reviewing', 'rejected', 'reject_submission', ($reasonCode ?: 'other').': '.$note, 'admin', $admin->id, $hash);
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
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['submission' => ['只有待审核提交可以开始审核。']]);
            }
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $locked->update(['status' => 'reviewing', 'review_started_at' => now()]);
            $claim->update(['status' => 'reviewing']);
            $this->recordChange($claim, 'submitted', 'reviewing', 'start_review', '审核员开始处理任务材料。', 'admin', $admin->id, $hash);

            return $locked->fresh();
        });
    }

    /** @return array<string,mixed> */
    public function present(TaskSubmission $submission): array
    {
        return [
            'id' => $submission->public_id, 'claim_id' => $submission->claim?->public_id, 'task_claim_id' => $submission->claim?->public_id,
            'submission_no' => $submission->submission_no, 'status' => $submission->status,
            'content' => $submission->content_snapshot, 'file_ids' => $submission->file_public_ids ?? [],
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
    private function validateStepSubmission(array $steps, array $content, array $fileIds): void
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
            if (! ($step['required'] ?? false)) {
                continue;
            }
            $id = (string) ($step['id'] ?? '');
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

    private function recordChange(TaskClaim $claim, ?string $from, string $to, string $action, string $reason, string $actorType, ?int $actorId, string $hash): void
    {
        DB::table('task_claim_status_changes')->insert([
            'task_claim_id' => $claim->id, 'from_status' => $from, 'to_status' => $to, 'action' => $action,
            'reason' => $reason, 'actor_type' => $actorType, 'actor_id' => $actorId, 'idempotency_key_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
