<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskVerificationEvent;
use App\Modules\Task\Models\TaskVersion;
use App\Modules\Task\Support\TaskClaimState;
use App\Modules\Task\Support\TaskCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskVerificationService
{
    public function __construct(
        private readonly TaskClaimService $claims,
        private readonly TaskRewardDeliveryService $rewards,
        private readonly ReferralService $referrals,
    ) {}

    /**
     * Consume an idempotent event emitted by trusted server-side business code.
     * `payload.delta` increments progress; legacy `goalMet=true` completes the target immediately.
     * Client-supplied HTTP payloads must never call this method directly.
     */
    public function consume(User $user, Task $task, string $eventType, string $source, string $eventKey, bool $goalMet, array $payload = []): TaskVerificationEvent
    {
        $hash = $this->fingerprint('task-verification:'.$source.':'.$eventKey);
        $existing = TaskVerificationEvent::query()->where('event_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $completed = false;
        $event = DB::transaction(function () use ($user, $task, $eventType, $source, $eventKey, $goalMet, $payload, $hash, &$completed): TaskVerificationEvent {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            [$mode, $content] = $this->publishedDefinition($lockedTask);
            if (! in_array($mode, ['claim_and_auto_verify', 'auto_enroll'], true)) {
                throw ValidationException::withMessages(['task' => ['该任务不接受自动核验事件。']]);
            }
            $expectedType = trim((string) ($content['progress_event_type'] ?? ''));
            if ($expectedType !== '' && $eventType !== $expectedType) {
                throw ValidationException::withMessages(['event_type' => ['事件类型与任务进度规则不匹配。']]);
            }

            $cycle = TaskCycle::resolve((string) ($content['task_type'] ?? 'one_time'));
            $claim = TaskClaim::query()->where('task_id', $lockedTask->id)->where('user_id', $user->id)
                ->where('cycle_key', $cycle['key'])->lockForUpdate()->first();
            if ($claim === null && $mode === 'auto_enroll') {
                $claim = $this->claims->claim($user, $lockedTask, 'verify-'.$eventKey, 'system');
                $claim = TaskClaim::query()->lockForUpdate()->findOrFail($claim->id);
            }
            if ($claim === null) {
                throw ValidationException::withMessages(['claim' => ['用户尚未领取本周期任务。']]);
            }
            if (in_array($claim->status, ['approved', 'cancelled', 'expired', 'appeal_rejected'], true)) {
                throw ValidationException::withMessages(['claim' => ['本周期任务实例已结束，不能继续累计进度。']]);
            }

            $target = max(1, (int) ($claim->progress_target ?: ($content['progress_target'] ?? 1)));
            // A legacy `goalMet=false` call is only an observation. Growth tasks opt in to
            // accumulation by explicitly sending a positive delta.
            $requestedDelta = array_key_exists('delta', $payload) ? max(0, (int) $payload['delta']) : 0;
            $current = min($target, max(0, (int) $claim->progress_current));
            $after = $goalMet ? $target : min($target, $current + $requestedDelta);
            $delta = $after - $current;
            $completed = $after >= $target;
            $now = now();

            $claim->update([
                'progress_started_at' => $delta > 0 ? ($claim->progress_started_at ?? $now) : $claim->progress_started_at,
                'progress_current' => $after,
                'progress_target' => $target,
                'last_progress_at' => $delta > 0 ? $now : $claim->last_progress_at,
                'execution_status' => $completed
                    ? TaskClaimState::EXECUTION_COMPLETED
                    : ($delta > 0 ? TaskClaimState::EXECUTION_IN_PROGRESS : $claim->execution_status),
            ]);

            $event = TaskVerificationEvent::query()->create([
                'task_id' => $lockedTask->id, 'task_claim_id' => $claim->id, 'user_id' => $user->id,
                'cycle_key' => $cycle['key'], 'event_type' => $eventType, 'source' => $source, 'event_key_hash' => $hash,
                'payload' => $payload, 'goal_met' => $completed, 'progress_delta' => $delta, 'progress_after' => $after,
                'status' => 'processed', 'processed_at' => $now,
            ]);

            if ($completed && $claim->status !== 'approved') {
                $from = $claim->status;
                $claim->update([
                    'status' => 'approved', 'execution_status' => TaskClaimState::EXECUTION_COMPLETED,
                    'review_status' => TaskClaimState::REVIEW_APPROVED, 'slot_status' => 'consumed', 'approved_at' => $now,
                ]);
                DB::table('task_claim_status_changes')->insert([
                    'task_claim_id' => $claim->id, 'from_status' => $from, 'to_status' => 'approved', 'action' => 'automatic_verification',
                    'from_execution_status' => TaskClaimState::fromLegacy($from)['execution_status'], 'to_execution_status' => TaskClaimState::EXECUTION_COMPLETED,
                    'from_review_status' => TaskClaimState::fromLegacy($from)['review_status'], 'to_review_status' => TaskClaimState::REVIEW_APPROVED,
                    'reason' => '可信服务端事件累计达到周期任务目标。', 'actor_type' => 'system', 'actor_id' => null,
                    'idempotency_key_hash' => $this->fingerprint('task-auto-approve:'.$event->id), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return $event;
        });

        if ($completed) {
            $claim = TaskClaim::query()->findOrFail($event->task_claim_id);
            $this->rewards->deliverForClaim($claim);
            $this->referrals->recordFirstTask($claim->fresh());
        }

        return $event->fresh();
    }

    /** @return array{string,array<string,mixed>} */
    private function publishedDefinition(Task $task): array
    {
        $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->published_version_no)->firstOrFail();
        $content = $version->content;

        return [(string) ($content['participation_mode'] ?? ''), $content];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
