<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskDataItem;
use App\Modules\Task\Models\TaskReview;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskRewardReservation;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Models\TaskVersion;
use App\Modules\Task\Support\TaskClaimState;
use App\Modules\Task\Support\TaskCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskClaimService
{
    public function __construct(private readonly TaskDataBatchService $dataBatches) {}

    /** @return array<int,array<string,mixed>> */
    public function hall(array $filters = []): array
    {
        return Task::query()->where('status', 'published')->latest('published_at')->get()
            ->map(fn (Task $task) => $this->publicTask($task))
            ->filter(function (array $task) use ($filters): bool {
                if ($task['visibility'] !== 'public') {
                    return false;
                }
                if (($filters['category'] ?? null) && $task['category'] !== $filters['category']) {
                    return false;
                }
                if (($filters['participation_mode'] ?? null) && $task['participation_mode'] !== $filters['participation_mode']) {
                    return false;
                }
                if (($filters['task_type'] ?? null) && $task['task_type'] !== $filters['task_type']) {
                    return false;
                }
                if (($filters['reward_type'] ?? null) && ! collect($task['rewards'])->contains('type', $filters['reward_type'])) {
                    return false;
                }
                if (($filters['keyword'] ?? null) && ! str_contains(mb_strtolower($task['title'].' '.($task['description_text'] ?? strip_tags($task['description']))), mb_strtolower($filters['keyword']))) {
                    return false;
                }
                if (($filters['claimable_only'] ?? false) && ! $task['can_claim']) {
                    return false;
                }

                return true;
            })->values()->all();
    }

    /** @return array<string,mixed> */
    public function publicTask(Task $task, bool $recordView = false): array
    {
        if ($task->status !== 'published' || $task->published_version_no === null) {
            throw ValidationException::withMessages(['task' => ['任务当前不可公开访问。']]);
        }
        $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->published_version_no)->firstOrFail();
        $content = $version->content;
        $publicContent = Arr::except($content, ['reward_budgets', 'virtual_view_count']);
        $virtualViewCount = max(0, (int) ($content['virtual_view_count'] ?? 0));
        $viewCount = (int) $task->view_count;
        if ($recordView && ($publicContent['visibility'] ?? null) === 'public') {
            Task::query()->whereKey($task->id)->increment('view_count');
            $viewCount++;
        }
        $budgets = TaskRewardBudget::query()->where('task_version_id', $version->id)->get();
        $remaining = $this->remainingSlots($budgets);
        $batchRemaining = $this->dataBatches->availableCount($version);
        if ($batchRemaining !== null) {
            $remaining = min($remaining, $batchRemaining);
        }
        $active = TaskClaim::query()->where('task_id', $task->id)->where('slot_status', 'reserved')->count();
        $successful = TaskClaim::query()->where('task_id', $task->id)->where('slot_status', 'consumed')->count();
        $completedClaims = TaskClaim::query()->where('task_id', $task->id)->whereNotNull('approved_at')->with('submissions')->get();
        $completionSamples = $completedClaims->map(function (TaskClaim $claim): ?float {
            $submission = $claim->submissions->whereNotNull('submitted_at')->sortBy('submitted_at')->first();
            if ($submission === null) {
                return null;
            }

            return $submission->submitted_at->diffInMinutes($claim->progress_started_at ?? $claim->created_at);
        })->filter(fn (?float $minutes) => $minutes !== null);
        $reviewSamples = TaskReview::query()
            ->join('task_claims', 'task_claims.id', '=', 'task_reviews.task_claim_id')
            ->join('task_submissions', 'task_submissions.id', '=', 'task_reviews.task_submission_id')
            ->where('task_claims.task_id', $task->id)
            ->whereNotNull('task_reviews.reviewed_at')
            ->whereNotNull('task_submissions.submitted_at')
            ->get(['task_reviews.reviewed_at', 'task_submissions.submitted_at'])
            ->map(fn ($review): float => CarbonImmutable::parse($review->reviewed_at)->diffInMinutes(CarbonImmutable::parse($review->submitted_at)));
        $reviewedClaimCount = TaskReview::query()
            ->join('task_claims', 'task_claims.id', '=', 'task_reviews.task_claim_id')
            ->where('task_claims.task_id', $task->id)
            ->distinct('task_reviews.task_claim_id')
            ->count('task_reviews.task_claim_id');
        $now = CarbonImmutable::now();
        $cycle = TaskCycle::resolve((string) $content['task_type'], $now);
        $started = CarbonImmutable::parse($content['claim_starts_at'])->lte($now);
        $notEnded = CarbonImmutable::parse($content['claim_ends_at'])->gte($now);
        $userClaimable = $content['participation_mode'] !== 'auto_enroll';

        $campusPublisherName = trim((string) ($content['publisher_display_name'] ?? ''));

        return [
            'id' => $task->public_id, ...$publicContent,
            'publisher' => [
                'id' => DB::table('admin_users')->where('id', $task->created_by_admin_id)->value('public_id'),
                'publisher_type' => $campusPublisherName !== '' ? 'campus_user' : 'platform_admin',
                'display_name' => $campusPublisherName !== '' ? $campusPublisherName : '平台任务运营',
                'verification_status' => 'verified',
                'publisher_badge' => $campusPublisherName !== '' ? '校园认证' : '平台发布',
                'avatar_url' => $content['publisher_avatar_url'] ?? null,
                'credit_score' => isset($content['publisher_credit_score']) ? (int) $content['publisher_credit_score'] : null,
            ],
            'status' => $task->status, 'published_at' => $task->published_at?->toIso8601String(), 'historical_claim_count' => TaskClaim::query()->where('task_id', $task->id)->count(),
            'active_reservation_count' => $active, 'successful_reward_count' => $successful, 'remaining_reward_slots' => $remaining,
            'completed_user_count' => $completedClaims->count(),
            'pass_rate_percent' => $reviewedClaimCount === 0 ? null : (int) round($completedClaims->count() / $reviewedClaimCount * 100),
            'average_completion_minutes' => $completionSamples->isEmpty() ? null : (int) round($completionSamples->average()),
            'average_review_minutes' => $reviewSamples->isEmpty() ? null : (int) round($reviewSamples->average()),
            'view_count' => $viewCount + $virtualViewCount,
            'current_cycle' => [
                'key' => $cycle['key'],
                'starts_at' => $cycle['starts_at']?->toIso8601String(),
                'ends_at' => $cycle['ends_at']?->toIso8601String(),
                'progress_target' => max(1, (int) ($content['progress_target'] ?? 1)),
                'progress_event_type' => $content['progress_event_type'] ?? null,
            ],
            'eligibility' => ['status' => 'eligible', 'message' => '领取时将重新校验账号、周期、名额和全部奖励预算。'],
            'can_claim' => $started && $notEnded && $remaining > 0 && $userClaimable,
            'claim_unavailable_reason' => ! $started ? 'not_started' : (! $notEnded ? 'ended' : ($remaining < 1 ? 'quota_exhausted' : (! $userClaimable ? 'ineligible' : null))),
            'can_report' => true, 'placements' => [], 'current_published_version' => $version->version_no,
        ];
    }

    public function claim(User $user, Task $task, string $idempotencyKey, string $source = 'user'): TaskClaim
    {
        $hash = $this->fingerprint('task-claim:'.$user->id.':'.$task->id.':'.$idempotencyKey);
        $existing = TaskClaim::query()->where('claim_idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $task, $hash, $source): TaskClaim {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedTask->status !== 'published' || $lockedTask->published_version_no === null) {
                throw ValidationException::withMessages(['task' => ['任务当前不可领取。']]);
            }
            if ($user->status !== 'active' || $user->phone_verified_at === null) {
                throw ValidationException::withMessages(['user' => ['领取任务前需要启用账号并绑定已验证手机号。']]);
            }
            $version = TaskVersion::query()->where('task_id', $lockedTask->id)->where('version_no', $lockedTask->published_version_no)->firstOrFail();
            $content = $version->content;
            $mode = $content['participation_mode'];
            if (($source === 'user' && ! in_array($mode, ['claim_and_submit', 'claim_and_auto_verify'], true)) || ($source === 'system' && $mode !== 'auto_enroll')) {
                throw ValidationException::withMessages(['task' => ['该参与方式不允许当前领取动作。']]);
            }
            $now = CarbonImmutable::now();
            if (CarbonImmutable::parse($content['claim_starts_at'])->gt($now) || CarbonImmutable::parse($content['claim_ends_at'])->lt($now)) {
                throw ValidationException::withMessages(['task' => ['当前不在领取时间内。']]);
            }
            $cycle = TaskCycle::resolve((string) $content['task_type'], $now);
            $cycleKey = $cycle['key'];
            $sameCycle = TaskClaim::query()->where('task_id', $lockedTask->id)->where('user_id', $user->id)->where('cycle_key', $cycleKey)->first();
            if ($sameCycle !== null) {
                throw ValidationException::withMessages(['task' => ['本周期已经领取过，取消后也不能重新领取。']]);
            }
            $cycleLimit = $content['reward_quota_per_cycle'] ?? null;
            if ($cycleLimit !== null && TaskClaim::query()->where('task_id', $lockedTask->id)->where('cycle_key', $cycleKey)->whereIn('slot_status', ['reserved', 'consumed'])->count() >= $cycleLimit) {
                throw ValidationException::withMessages(['task' => ['本周期奖励名额已满。']]);
            }
            $budgets = TaskRewardBudget::query()->where('task_version_id', $version->id)->orderBy('id')->lockForUpdate()->get();
            if ($budgets->isEmpty() || $this->remainingSlots($budgets) < 1) {
                throw ValidationException::withMessages(['task' => ['任务名额或奖励预算已用尽。']]);
            }
            $deadline = $now->addHours((int) $content['completion_window_hours']);
            $globalDeadline = CarbonImmutable::parse($content['submit_ends_at']);
            if ($globalDeadline->lt($deadline)) {
                $deadline = $globalDeadline;
            }
            if ($cycle['ends_at'] !== null && $cycle['ends_at']->lt($deadline)) {
                $deadline = $cycle['ends_at'];
            }
            $claim = TaskClaim::query()->create([
                'task_id' => $lockedTask->id, 'task_version_id' => $version->id, 'user_id' => $user->id,
                'enrollment_source' => $source, 'cycle_key' => $cycleKey,
                'cycle_starts_at' => $cycle['starts_at'], 'cycle_ends_at' => $cycle['ends_at'],
                'task_snapshot' => ['task_id' => $lockedTask->public_id, 'version_no' => $version->version_no, ...$content],
                'execution_status' => TaskClaimState::EXECUTION_RESERVED, 'review_status' => null,
                'progress_current' => 0, 'progress_target' => max(1, (int) ($content['progress_target'] ?? 1)),
                'completion_deadline_at' => $deadline, 'claim_idempotency_key_hash' => $hash,
            ]);
            $this->dataBatches->allocateForClaim($claim, $version);
            foreach ($budgets as $budget) {
                $available = $budget->locked_amount - $budget->reserved_amount - $budget->issued_amount - $budget->released_amount;
                if ($available < $budget->unit_amount) {
                    throw ValidationException::withMessages(['task' => ['一个或多个奖励组件预算不足。']]);
                }
                $budget->update(['reserved_amount' => $budget->reserved_amount + $budget->unit_amount, 'version' => $budget->version + 1]);
                TaskRewardReservation::query()->create([
                    'task_claim_id' => $claim->id, 'task_reward_budget_id' => $budget->id,
                    'reward_sequence' => $budget->reward_sequence, 'asset_type' => $budget->asset_type, 'amount' => $budget->unit_amount,
                    'idempotency_key_hash' => $this->fingerprint('reservation:'.$claim->id.':'.$budget->reward_sequence), 'reserved_at' => now(),
                ]);
            }
            $this->recordChange($claim, null, 'claimed', $source === 'user' ? 'claim' : 'auto_enroll', '成功占用名额并预留全部奖励。', $source, $user->id, $hash);

            return $claim->fresh('reservations');
        });
    }

    public function cancel(User $user, TaskClaim $claim, string $reason, string $idempotencyKey): TaskClaim
    {
        $hash = $this->fingerprint('task-claim-cancel:'.$claim->id.':'.$idempotencyKey);
        if (DB::table('task_claim_status_changes')->where('idempotency_key_hash', $hash)->exists()) {
            return $claim->fresh();
        }

        return DB::transaction(function () use ($user, $claim, $reason, $hash): TaskClaim {
            $locked = TaskClaim::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($claim->id);
            if ($locked->status !== 'claimed' || $locked->progress_started_at !== null || $locked->submission_count > 0 || $locked->cancel_count >= 1) {
                throw ValidationException::withMessages(['claim' => ['当前用户任务不能取消。']]);
            }
            $this->releaseReservations($locked);
            $this->dataBatches->releaseForClaim($locked);
            $locked->update(['status' => 'cancelled', 'execution_status' => TaskClaimState::EXECUTION_ABANDONED, 'review_status' => TaskClaimState::REVIEW_VOIDED, 'slot_status' => 'released', 'cancel_count' => $locked->cancel_count + 1, 'cancelled_at' => now()]);
            $this->recordChange($locked, 'claimed', 'cancelled', 'cancel', $reason, 'user', $user->id, $hash);

            return $locked->fresh('reservations');
        });
    }

    public function expireDue(): int
    {
        $count = 0;
        TaskClaim::query()->where('status', 'claimed')->where('completion_deadline_at', '<=', now())->orderBy('id')->each(function (TaskClaim $claim) use (&$count): void {
            DB::transaction(function () use ($claim, &$count): void {
                $locked = TaskClaim::query()->lockForUpdate()->findOrFail($claim->id);
                if ($locked->status !== 'claimed' || $locked->completion_deadline_at->isFuture()) {
                    return;
                }
                $hash = $this->fingerprint('task-claim-expire:'.$locked->id);
                $this->releaseReservations($locked);
                $this->dataBatches->releaseForClaim($locked);
                $locked->update(['status' => 'expired', 'execution_status' => TaskClaimState::EXECUTION_EXPIRED, 'review_status' => TaskClaimState::REVIEW_VOIDED, 'slot_status' => 'released']);
                $this->recordChange($locked, 'claimed', 'expired', 'expire', '超过实际完成期限，系统释放名额和奖励预留。', 'system', null, $hash);
                $count++;
            });
        });

        return $count;
    }

    public function releaseAfterFinalRejection(TaskClaim $claim, string $reason, string $eventKey): TaskClaim
    {
        return DB::transaction(function () use ($claim, $reason, $eventKey): TaskClaim {
            $locked = TaskClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($locked->slot_status === 'released') {
                return $locked;
            }
            if (! in_array($locked->status, ['rejected', 'appealed', 'appeal_rejected'], true)) {
                throw ValidationException::withMessages(['claim' => ['只有最终驳回的任务可以释放预留。']]);
            }
            $this->releaseReservations($locked);
            $this->dataBatches->releaseForClaim($locked);
            $from = $locked->status;
            $locked->update(['status' => 'appeal_rejected', 'execution_status' => TaskClaimState::EXECUTION_TERMINATED, 'review_status' => TaskClaimState::REVIEW_REJECTED, 'slot_status' => 'released']);
            $this->recordChange($locked, $from, 'appeal_rejected', 'final_rejection', $reason, 'system', null, $this->fingerprint('final-reject:'.$locked->id.':'.$eventKey));

            return $locked->fresh('reservations');
        });
    }

    /** @return array<string,mixed> */
    public function presentClaim(TaskClaim $claim): array
    {
        $latestSubmission = $claim->submissions()->latest('submission_no')->first();
        $latestReview = $claim->reviews()->latest('reviewed_at')->first();
        $dataItem = TaskDataItem::query()->where('allocated_task_claim_id', $claim->id)->first();
        $workflow = $this->claimWorkflow($claim, $latestSubmission);

        return [
            'id' => $claim->public_id, 'task_id' => $claim->task_snapshot['task_id'], 'title' => $claim->task_snapshot['title'],
            'description' => $claim->task_snapshot['description'] ?? '',
            'description_text' => $claim->task_snapshot['description_text'] ?? strip_tags((string) ($claim->task_snapshot['description'] ?? '')),
            'business_scenario' => $claim->task_snapshot['business_scenario'] ?? 'platform_task',
            'project_name' => $claim->task_snapshot['project_name'] ?? null,
            'cover_image_url' => $claim->task_snapshot['cover_image_url'] ?? null,
            'supported_devices' => $claim->task_snapshot['supported_devices'] ?? ['android', 'ios'],
            'category' => $claim->task_snapshot['category'] ?? 'campaign', 'task_type' => $claim->task_snapshot['task_type'] ?? 'one_time',
            'tags' => $claim->task_snapshot['tags'] ?? [], 'steps' => $claim->task_snapshot['steps'] ?? [], 'rules' => $claim->task_snapshot['rules'] ?? [],
            'attachment_rules' => $claim->task_snapshot['attachment_rules'] ?? [],
            'enrollment_source' => $claim->enrollment_source, 'cycle_key' => $claim->cycle_key,
            'cycle_starts_at' => $claim->cycle_starts_at?->toIso8601String(),
            'cycle_ends_at' => $claim->cycle_ends_at?->toIso8601String(),
            'progress' => [
                'current' => (int) $claim->progress_current,
                'target' => max(1, (int) $claim->progress_target),
                'percent' => min(100, (int) floor(((int) $claim->progress_current / max(1, (int) $claim->progress_target)) * 100)),
                'last_progress_at' => $claim->last_progress_at?->toIso8601String(),
            ],
            'status' => $claim->status,
            'execution_status' => $claim->execution_status, 'execution_status_label' => TaskClaimState::executionLabel($claim->execution_status),
            'review_status' => $claim->review_status, 'review_status_label' => TaskClaimState::reviewLabel($claim->review_status),
            'workflow' => $workflow,
            'slot_status' => $claim->slot_status, 'cancel_count' => $claim->cancel_count,
            'submission_count' => $claim->submission_count, 'submission_limit' => $claim->task_snapshot['submission_limit'],
            'appeal_count' => $claim->appeal_count, 'appeal_limit' => $claim->task_snapshot['appeal_limit'], 'reward_status' => $claim->reward_status,
            'completion_deadline_at' => $claim->completion_deadline_at?->toIso8601String(),
            'progress_started_at' => $claim->progress_started_at?->toIso8601String(),
            'rewards' => $claim->task_snapshot['rewards'], 'created_at' => $claim->created_at?->toIso8601String(),
            'data_item' => $dataItem === null ? null : [
                'id' => $dataItem->public_id,
                'row_no' => $dataItem->row_no,
                'payload' => $dataItem->mapped_payload,
            ],
            'current_submission' => $latestSubmission === null ? null : [
                'id' => $latestSubmission->public_id, 'submission_no' => $latestSubmission->submission_no, 'status' => $latestSubmission->status,
                'submitted_at' => $latestSubmission->submitted_at?->toIso8601String(), 'review_due_at' => $latestSubmission->review_due_at?->toIso8601String(),
                'review_started_at' => $latestSubmission->review_started_at?->toIso8601String(),
                'revision_step_ids' => $latestSubmission->revision_step_ids ?? [],
            ],
            'last_review' => $latestReview === null ? null : [
                'decision' => $latestReview->decision, 'reason_code' => $latestReview->reason_code, 'note' => $latestReview->note,
                'reviewed_at' => $latestReview->reviewed_at?->toIso8601String(),
                'step_results' => $latestReview->stepReviews()->orderBy('id')->get()->map(fn ($step) => [
                    'step_id' => $step->step_id, 'step_title' => $step->step_title, 'decision' => $step->decision,
                    'reason_code' => $step->reason_code, 'note' => $step->note,
                ])->values()->all(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function claimWorkflow(TaskClaim $claim, ?TaskSubmission $latestSubmission): array
    {
        $allStepIds = collect($claim->task_snapshot['steps'] ?? [])->pluck('id')->filter()->values()->all();
        $revisionStepIds = array_values(array_unique($latestSubmission?->revision_step_ids ?? []));
        $submissionLimit = (int) ($claim->task_snapshot['submission_limit'] ?? 3);
        $deadlineActive = $claim->completion_deadline_at?->isFuture() ?? false;
        $canSubmit = in_array($claim->status, ['claimed', 'withdrawn', 'rejected'], true)
            && $claim->submission_count < $submissionLimit
            && $deadlineActive;
        $canWithdraw = $claim->status === 'submitted'
            && $latestSubmission !== null
            && $latestSubmission->review_started_at === null;
        $appealLimit = (int) ($claim->task_snapshot['appeal_limit'] ?? 1);
        $canAppeal = $claim->status === 'rejected'
            && ! $canSubmit
            && $claim->appeal_count < $appealLimit;
        $canCancel = $claim->status === 'claimed'
            && $claim->progress_started_at === null
            && $claim->submission_count === 0
            && $claim->cancel_count < 1;

        $primaryAction = match (true) {
            $canSubmit && $revisionStepIds !== [] => ['code' => 'resubmit_failed_steps', 'label' => '补交未通过步骤'],
            $canSubmit => ['code' => 'submit', 'label' => '提交审核'],
            $canWithdraw => ['code' => 'withdraw', 'label' => '撤回本次提交'],
            $canAppeal => ['code' => 'appeal', 'label' => '申请复核'],
            $claim->status === 'approved' => ['code' => 'view_rewards', 'label' => '查看奖励到账'],
            default => ['code' => 'wait', 'label' => '等待处理'],
        };

        $message = match ($primaryAction['code']) {
            'resubmit_failed_steps' => '只需补交未通过步骤；已通过材料会由系统沿用，请勿重复提交。',
            'submit' => '按步骤完成材料后提交，审核开始前可以撤回，但不会返还提交次数。',
            'withdraw' => '材料正在排队，审核员接单前仍可撤回修改。',
            'appeal' => '提交次数已用完，如对审核结果有异议可以申请异人复核。',
            'view_rewards' => '任务已通过，奖励组件将分别发放并显示到账结果。',
            default => in_array($claim->status, ['submitted', 'reviewing'], true) ? '材料已提交，请等待审核结果。' : '当前任务暂无可执行操作。',
        };

        return [
            'primary_action' => $primaryAction,
            'allowed_actions' => array_values(array_filter([
                $canSubmit ? ($revisionStepIds === [] ? 'submit' : 'resubmit_failed_steps') : null,
                $canWithdraw ? 'withdraw' : null,
                $canAppeal ? 'appeal' : null,
                $canCancel ? 'cancel' : null,
                $claim->status === 'approved' ? 'view_rewards' : null,
            ])),
            'editable_step_ids' => $canSubmit ? ($revisionStepIds === [] ? $allStepIds : $revisionStepIds) : [],
            'locked_step_ids' => $revisionStepIds === [] ? [] : array_values(array_diff($allStepIds, $revisionStepIds)),
            'revision_only' => $canSubmit && $revisionStepIds !== [],
            'message' => $message,
        ];
    }

    private function remainingSlots(Collection $budgets): int
    {
        if ($budgets->isEmpty()) {
            return 0;
        }

        return (int) $budgets->map(function (TaskRewardBudget $budget): int {
            if ($budget->unit_amount < 1) {
                return 0;
            }
            $available = $budget->locked_amount - $budget->reserved_amount - $budget->issued_amount - $budget->released_amount;

            return max(0, intdiv($available, $budget->unit_amount));
        })->min();
    }

    private function cycleKey(string $taskType, CarbonImmutable $now): string
    {
        $cn = $now->setTimezone('Asia/Shanghai');

        return match ($taskType) {
            'one_time' => 'once',
            'milestone' => 'milestone',
            'weekly' => $cn->format('o-\WW'),
            'monthly' => $cn->format('Y-m'),
            'daily' => $cn->format('Y-m-d'),
            'repeatable' => 'repeat-'.$cn->format('Y-m-d'),
            default => throw ValidationException::withMessages(['task' => ['任务周期类型无效。']]),
        };
    }

    private function releaseReservations(TaskClaim $claim): void
    {
        TaskRewardReservation::query()->where('task_claim_id', $claim->id)->where('status', 'reserved')->orderBy('id')->lockForUpdate()->get()
            ->each(function (TaskRewardReservation $reservation): void {
                $budget = TaskRewardBudget::query()->lockForUpdate()->findOrFail($reservation->task_reward_budget_id);
                if ($budget->reserved_amount < $reservation->amount) {
                    throw new \RuntimeException('Task reward reservation and budget summary mismatch.');
                }
                $budget->update(['reserved_amount' => $budget->reserved_amount - $reservation->amount, 'version' => $budget->version + 1]);
                $reservation->update(['status' => 'released', 'settled_at' => now()]);
            });
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
