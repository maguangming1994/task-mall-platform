<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskReview;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskRewardReservation;
use App\Modules\Task\Models\TaskVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskClaimService
{
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
        $started = CarbonImmutable::parse($content['claim_starts_at'])->lte($now);
        $notEnded = CarbonImmutable::parse($content['claim_ends_at'])->gte($now);
        $userClaimable = $content['participation_mode'] !== 'auto_enroll';

        return [
            'id' => $task->public_id, ...$publicContent,
            'publisher' => ['id' => DB::table('admin_users')->where('id', $task->created_by_admin_id)->value('public_id'), 'publisher_type' => 'platform_admin', 'display_name' => '平台任务运营', 'verification_status' => 'verified', 'publisher_badge' => '平台发布'],
            'status' => $task->status, 'historical_claim_count' => TaskClaim::query()->where('task_id', $task->id)->count(),
            'active_reservation_count' => $active, 'successful_reward_count' => $successful, 'remaining_reward_slots' => $remaining,
            'completed_user_count' => $completedClaims->count(),
            'pass_rate_percent' => $reviewedClaimCount === 0 ? null : (int) round($completedClaims->count() / $reviewedClaimCount * 100),
            'average_completion_minutes' => $completionSamples->isEmpty() ? null : (int) round($completionSamples->average()),
            'average_review_minutes' => $reviewSamples->isEmpty() ? null : (int) round($reviewSamples->average()),
            'view_count' => $viewCount + $virtualViewCount,
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
            $cycleKey = $this->cycleKey($content['task_type'], $now);
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
            $claim = TaskClaim::query()->create([
                'task_id' => $lockedTask->id, 'task_version_id' => $version->id, 'user_id' => $user->id,
                'enrollment_source' => $source, 'cycle_key' => $cycleKey,
                'task_snapshot' => ['task_id' => $lockedTask->public_id, 'version_no' => $version->version_no, ...$content],
                'completion_deadline_at' => $deadline, 'claim_idempotency_key_hash' => $hash,
            ]);
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
            $locked->update(['status' => 'cancelled', 'slot_status' => 'released', 'cancel_count' => $locked->cancel_count + 1, 'cancelled_at' => now()]);
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
                $locked->update(['status' => 'expired', 'slot_status' => 'released']);
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
            $from = $locked->status;
            $locked->update(['status' => 'appeal_rejected', 'slot_status' => 'released']);
            $this->recordChange($locked, $from, 'appeal_rejected', 'final_rejection', $reason, 'system', null, $this->fingerprint('final-reject:'.$locked->id.':'.$eventKey));

            return $locked->fresh('reservations');
        });
    }

    /** @return array<string,mixed> */
    public function presentClaim(TaskClaim $claim): array
    {
        $latestSubmission = $claim->submissions()->latest('submission_no')->first();
        $latestReview = $claim->reviews()->latest('reviewed_at')->first();

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
            'status' => $claim->status, 'slot_status' => $claim->slot_status, 'cancel_count' => $claim->cancel_count,
            'submission_count' => $claim->submission_count, 'submission_limit' => $claim->task_snapshot['submission_limit'],
            'appeal_count' => $claim->appeal_count, 'appeal_limit' => $claim->task_snapshot['appeal_limit'], 'reward_status' => $claim->reward_status,
            'completion_deadline_at' => $claim->completion_deadline_at?->toIso8601String(),
            'progress_started_at' => $claim->progress_started_at?->toIso8601String(),
            'rewards' => $claim->task_snapshot['rewards'], 'created_at' => $claim->created_at?->toIso8601String(),
            'current_submission' => $latestSubmission === null ? null : [
                'id' => $latestSubmission->public_id, 'submission_no' => $latestSubmission->submission_no, 'status' => $latestSubmission->status,
                'submitted_at' => $latestSubmission->submitted_at?->toIso8601String(), 'review_due_at' => $latestSubmission->review_due_at?->toIso8601String(),
                'review_started_at' => $latestSubmission->review_started_at?->toIso8601String(),
            ],
            'last_review' => $latestReview === null ? null : [
                'decision' => $latestReview->decision, 'reason_code' => $latestReview->reason_code, 'note' => $latestReview->note,
                'reviewed_at' => $latestReview->reviewed_at?->toIso8601String(),
            ],
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
            'weekly' => $cn->format('o-\WW'),
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
