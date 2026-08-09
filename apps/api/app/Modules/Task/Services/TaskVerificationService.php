<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskVerificationEvent;
use App\Modules\Task\Models\TaskVersion;
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
     * Client-supplied HTTP payloads must never call this method directly.
     */
    public function consume(User $user, Task $task, string $eventType, string $source, string $eventKey, bool $goalMet, array $payload = []): TaskVerificationEvent
    {
        $hash = $this->fingerprint('task-verification:'.$source.':'.$eventKey);
        $existing = TaskVerificationEvent::query()->where('event_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $event = DB::transaction(function () use ($user, $task, $eventType, $source, $eventKey, $goalMet, $payload, $hash): TaskVerificationEvent {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $mode = $this->publishedMode($lockedTask);
            if (! in_array($mode, ['claim_and_auto_verify', 'auto_enroll'], true)) {
                throw ValidationException::withMessages(['task' => ['该任务不接受自动核验事件。']]);
            }
            $claim = TaskClaim::query()->where('task_id', $lockedTask->id)->where('user_id', $user->id)
                ->whereIn('status', ['claimed', 'rejected'])->latest('id')->lockForUpdate()->first();
            if ($claim === null && $mode === 'auto_enroll') {
                $claim = $this->claims->claim($user, $lockedTask, 'verify-'.$eventKey, 'system');
            }
            if ($claim === null) {
                throw ValidationException::withMessages(['claim' => ['用户尚未领取该自动核验任务。']]);
            }
            if ($claim->progress_started_at === null) {
                $claim->update(['progress_started_at' => now()]);
            }
            $event = TaskVerificationEvent::query()->create([
                'task_id' => $lockedTask->id, 'task_claim_id' => $claim->id, 'user_id' => $user->id,
                'event_type' => $eventType, 'source' => $source, 'event_key_hash' => $hash,
                'payload' => $payload, 'goal_met' => $goalMet, 'status' => 'processed', 'processed_at' => now(),
            ]);
            if ($goalMet && $claim->status !== 'approved') {
                $from = $claim->status;
                $claim->update(['status' => 'approved', 'slot_status' => 'consumed', 'approved_at' => now()]);
                DB::table('task_claim_status_changes')->insert([
                    'task_claim_id' => $claim->id, 'from_status' => $from, 'to_status' => 'approved', 'action' => 'automatic_verification',
                    'reason' => '可信服务端事件达到任务目标。', 'actor_type' => 'system', 'actor_id' => null,
                    'idempotency_key_hash' => $this->fingerprint('task-auto-approve:'.$event->id), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $event;
        });

        if ($goalMet) {
            $claim = TaskClaim::query()->findOrFail($event->task_claim_id);
            $this->rewards->deliverForClaim($claim);
            $this->referrals->recordFirstTask($claim->fresh());
        }

        return $event->fresh();
    }

    private function publishedMode(Task $task): string
    {
        $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->published_version_no)->firstOrFail();

        return (string) ($version->content['participation_mode'] ?? '');
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
