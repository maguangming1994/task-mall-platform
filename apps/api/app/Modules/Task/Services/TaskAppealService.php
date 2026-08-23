<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Task\Models\TaskAppeal;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskReview;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Support\TaskClaimState;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskAppealService
{
    public function __construct(
        private readonly TaskRewardDeliveryService $rewards,
        private readonly TaskClaimService $claims,
        private readonly ReferralService $referrals,
    ) {}

    public function create(User $user, TaskClaim $claim, string $reason, array $fileIds, string $idempotencyKey): TaskAppeal
    {
        $hash = $this->fingerprint('task-appeal:'.$claim->id.':'.$idempotencyKey);
        $existing = TaskAppeal::query()->where('create_idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $this->validateEvidence($user, $fileIds);

        return DB::transaction(function () use ($user, $claim, $reason, $fileIds, $hash): TaskAppeal {
            $locked = TaskClaim::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($claim->id);
            if ($locked->status !== 'rejected') {
                throw ValidationException::withMessages(['claim' => ['只有被驳回的任务可以申诉。']]);
            }
            $limit = (int) ($locked->task_snapshot['appeal_limit'] ?? 1);
            $submissionLimit = (int) ($locked->task_snapshot['submission_limit'] ?? 3);
            if ($locked->submission_count < $submissionLimit && $locked->completion_deadline_at->isFuture()) {
                throw ValidationException::withMessages(['appeal' => ['仍可重新提交材料；只有最终驳回后才能申诉。']]);
            }
            if ($locked->appeal_count >= $limit) {
                throw ValidationException::withMessages(['appeal' => ['申诉次数已用完。']]);
            }
            $submission = TaskSubmission::query()->where('task_claim_id', $locked->id)->where('status', 'rejected')->latest('id')->lockForUpdate()->firstOrFail();
            $review = TaskReview::query()->where('task_submission_id', $submission->id)->where('decision', 'reject')->latest('id')->firstOrFail();
            $deadline = $review->reviewed_at->addDays((int) ($locked->task_snapshot['appeal_deadline_days'] ?? 7));
            if ($deadline->isPast()) {
                throw ValidationException::withMessages(['appeal' => ['申诉期限已过。']]);
            }
            $number = $locked->appeal_count + 1;
            $appeal = TaskAppeal::query()->create([
                'task_claim_id' => $locked->id, 'task_submission_id' => $submission->id, 'appeal_no' => $number,
                'user_id' => $user->id, 'reason' => $reason, 'evidence_file_public_ids' => array_values($fileIds),
                'original_reviewer_admin_id' => $review->reviewer_admin_id, 'create_idempotency_key_hash' => $hash,
                'appeal_deadline_at' => $deadline,
            ]);
            $locked->update(['status' => 'appealed', 'execution_status' => TaskClaimState::EXECUTION_UNDER_APPEAL, 'review_status' => TaskClaimState::REVIEW_REJECTED, 'appeal_count' => $number]);
            $this->recordChange($locked, 'rejected', 'appealed', 'appeal', $reason, 'user', $user->id, $hash);

            return $appeal->fresh();
        });
    }

    public function review(AdminUser $admin, TaskAppeal $appeal, string $decision, string $reason, string $idempotencyKey): TaskAppeal
    {
        $hash = $this->fingerprint('task-appeal-review:'.$appeal->id.':'.$idempotencyKey);
        $existing = TaskAppeal::query()->where('review_idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $reviewed = DB::transaction(function () use ($admin, $appeal, $decision, $reason, $hash): TaskAppeal {
            $locked = TaskAppeal::query()->lockForUpdate()->findOrFail($appeal->id);
            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['appeal' => ['该申诉已经处理。']]);
            }
            if ($locked->original_reviewer_admin_id === $admin->id) {
                throw ValidationException::withMessages(['reviewer' => ['申诉必须由不同于原审核人的管理员处理。']]);
            }
            $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
            $locked->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewer_admin_id' => $admin->id,
                'decision' => $decision, 'result_reason' => $reason, 'review_idempotency_key_hash' => $hash, 'reviewed_at' => now(),
            ]);
            if ($decision === 'approve') {
                $claim->update(['status' => 'approved', 'execution_status' => TaskClaimState::EXECUTION_COMPLETED, 'review_status' => TaskClaimState::REVIEW_APPROVED, 'slot_status' => 'consumed', 'approved_at' => now()]);
                TaskSubmission::query()->whereKey($locked->task_submission_id)->update(['status' => 'approved']);
                $this->recordChange($claim, 'appealed', 'approved', 'approve_appeal', $reason, 'admin', $admin->id, $hash);
            }

            return $locked->fresh();
        });

        $claim = TaskClaim::query()->findOrFail($reviewed->task_claim_id);
        if ($decision === 'approve') {
            $this->rewards->deliverForClaim($claim);
            $this->referrals->recordFirstTask($claim->fresh());
        } else {
            $this->claims->releaseAfterFinalRejection($claim, $reason, 'appeal-'.$reviewed->id);
        }

        return $reviewed->fresh();
    }

    public function expireWindows(): int
    {
        $count = 0;
        TaskClaim::query()->where('status', 'rejected')->orderBy('id')->each(function (TaskClaim $claim) use (&$count): void {
            $reviewedAt = TaskReview::query()->where('task_claim_id', $claim->id)->where('decision', 'reject')->latest('id')->first()?->reviewed_at;
            if ($reviewedAt === null || now()->lt($reviewedAt->copy()->addDays((int) ($claim->task_snapshot['appeal_deadline_days'] ?? 7)))) {
                return;
            }
            $this->claims->releaseAfterFinalRejection($claim, '申诉期限已过，释放任务名额和奖励预留。', 'appeal-window-expired');
            $count++;
        });

        return $count;
    }

    public function present(TaskAppeal $appeal): array
    {
        return [
            'id' => $appeal->public_id, 'claim_id' => $appeal->claim?->public_id, 'appeal_no' => $appeal->appeal_no,
            'status' => $appeal->status, 'reason' => $appeal->reason, 'evidence_file_ids' => $appeal->evidence_file_public_ids ?? [],
            'result_reason' => $appeal->result_reason, 'appeal_deadline_at' => $appeal->appeal_deadline_at?->toIso8601String(),
            'created_at' => $appeal->created_at?->toIso8601String(), 'reviewed_at' => $appeal->reviewed_at?->toIso8601String(),
        ];
    }

    private function validateEvidence(User $user, array $fileIds): void
    {
        if (count($fileIds) > 9 || count($fileIds) !== count(array_unique($fileIds))) {
            throw ValidationException::withMessages(['evidence_file_ids' => ['申诉证据最多 9 个且不能重复。']]);
        }
        $files = UploadedFile::query()->whereIn('public_id', $fileIds)->get();
        if ($files->count() !== count($fileIds) || $files->contains(fn (UploadedFile $file): bool => $file->owner_user_id !== $user->id || ! in_array($file->purpose, ['task_submission', 'task_report'], true) || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['evidence_file_ids' => ['证据必须属于当前用户且已通过安全扫描。']]);
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
