<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskReport;
use App\Modules\Task\Models\TaskRiskCase;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskRiskService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function assessSubmission(TaskSubmission $submission): void
    {
        $text = preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) ($submission->content_snapshot['text'] ?? '')))) ?? '';
        $textHash = $text === '' ? null : hash('sha256', $text);
        $files = UploadedFile::query()->whereIn('public_id', $submission->file_public_ids ?? [])->get();
        DB::table('task_submission_fingerprints')->insertOrIgnore([
            'task_claim_id' => $submission->task_claim_id, 'task_submission_id' => $submission->id,
            'normalized_text_hash' => $textHash, 'file_hashes' => json_encode($files->pluck('sha256')->filter()->values(), JSON_THROW_ON_ERROR),
            'feature_version' => 'v1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $hits = [];
        if ($text !== '' && preg_match('/(?:https?:\/\/|www\.)/iu', $text)) {
            $hits[] = 'external_link';
        }
        if ($text !== '' && preg_match('/(?:密码|验证码|支付密码|password|token)/iu', $text)) {
            $hits[] = 'credential_collection';
        }
        if ($textHash !== null && DB::table('task_submission_fingerprints')->where('normalized_text_hash', $textHash)->where('task_submission_id', '!=', $submission->id)->exists()) {
            $hits[] = 'duplicate_text';
        }
        $level = in_array('credential_collection', $hits, true) || in_array('duplicate_text', $hits, true) ? 'high' : ($hits === [] ? 'low' : 'medium');
        DB::table('task_risk_assessments')->insert([
            'public_id' => (string) Str::ulid(), 'object_type' => 'task_submission', 'object_id' => $submission->id,
            'stage' => 'submission', 'rule_version' => 'v1', 'hit_codes' => json_encode($hits, JSON_THROW_ON_ERROR),
            'risk_level' => $level, 'evidence_summary' => json_encode(['text_hash' => $textHash, 'file_count' => $files->count()], JSON_THROW_ON_ERROR),
            'recommendation' => $level === 'low' ? 'allow_review' : 'manual_review', 'requires_manual_review' => $level !== 'low',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($level !== 'low' && ! TaskRiskCase::query()->where('task_claim_id', $submission->task_claim_id)->whereIn('status', ['open', 'reviewing'])->exists()) {
            $submission->loadMissing('claim');
            TaskRiskCase::query()->create([
                'task_id' => $submission->claim->task_id, 'task_claim_id' => $submission->task_claim_id,
                'source' => 'submission_rule', 'risk_level' => $level, 'reward_frozen' => true,
                'response_due_at' => now()->addHours($this->responseHours($level)),
            ]);
        }
    }

    public function report(User $user, Task $task, string $reasonCode, string $description, array $fileIds, string $idempotencyKey): TaskReport
    {
        $hash = $this->fingerprint('task-report:'.$user->id.':'.$task->id.':'.$idempotencyKey);
        $existing = TaskReport::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (TaskReport::query()->where('task_id', $task->id)->where('reporter_user_id', $user->id)->whereIn('status', ['open', 'reviewing'])->exists()) {
            throw ValidationException::withMessages(['report' => ['你对该任务已有进行中的举报。']]);
        }
        $this->validateEvidence($user, $fileIds);
        $level = in_array($reasonCode, ['fraud', 'illegal', 'privacy', 'dangerous'], true) ? 'high' : 'medium';

        return DB::transaction(function () use ($user, $task, $reasonCode, $description, $fileIds, $hash, $level): TaskReport {
            $case = TaskRiskCase::query()->create([
                'task_id' => $task->id, 'source' => 'user_report', 'risk_level' => $level,
                'reward_frozen' => $level === 'high', 'response_due_at' => now()->addHours($this->responseHours($level)),
            ]);

            return TaskReport::query()->create([
                'task_id' => $task->id, 'reporter_user_id' => $user->id, 'task_risk_case_id' => $case->id,
                'reason_code' => $reasonCode, 'description' => $description, 'evidence_file_public_ids' => array_values($fileIds),
                'idempotency_key_hash' => $hash,
            ]);
        });
    }

    public function review(AdminUser $admin, TaskRiskCase $case, string $decision, array $actions, string $reason, string $idempotencyKey): TaskRiskCase
    {
        $hash = $this->fingerprint('task-risk-review:'.$case->id.':'.$idempotencyKey);
        if (DB::table('task_risk_actions')->where('task_risk_case_id', $case->id)->where('idempotency_key_hash', $hash)->exists()) {
            return $case->fresh();
        }

        return DB::transaction(function () use ($admin, $case, $decision, $actions, $reason, $hash): TaskRiskCase {
            $locked = TaskRiskCase::query()->lockForUpdate()->findOrFail($case->id);
            if (! in_array($locked->status, ['open', 'reviewing'], true)) {
                throw ValidationException::withMessages(['case' => ['该风险案件已经结案。']]);
            }
            if ($decision === 'request_more_information') {
                $locked->update(['status' => 'reviewing', 'assigned_admin_id' => $admin->id, 'conclusion' => $reason]);
            } else {
                $locked->update([
                    'status' => 'resolved', 'assigned_admin_id' => $admin->id, 'conclusion' => $reason,
                    'reward_frozen' => $decision === 'confirm_violation' && in_array('freeze_reward', $actions, true), 'resolved_at' => now(),
                ]);
                TaskReport::query()->where('task_risk_case_id', $locked->id)->update(['status' => $decision === 'clear' ? 'cleared' : 'confirmed']);
            }
            foreach (array_values(array_unique($actions)) as $action) {
                DB::table('task_risk_actions')->insert([
                    'public_id' => (string) Str::ulid(), 'task_risk_case_id' => $locked->id, 'action' => $action,
                    'reason' => $reason, 'admin_user_id' => $admin->id, 'idempotency_key_hash' => $hash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (in_array($action, ['hide', 'stop_claiming'], true)) {
                    Task::query()->whereKey($locked->task_id)->whereIn('status', ['published', 'scheduled'])->update(['status' => 'paused', 'lock_version' => DB::raw('lock_version + 1')]);
                }
            }

            return $locked->fresh();
        });
    }

    public function present(TaskRiskCase $case): array
    {
        return [
            'id' => $case->public_id, 'task_id' => Task::query()->whereKey($case->task_id)->value('public_id'),
            'source' => $case->source, 'risk_level' => $case->risk_level, 'status' => $case->status,
            'reward_frozen' => $case->reward_frozen, 'conclusion' => $case->conclusion,
            'response_due_at' => $case->response_due_at?->toIso8601String(), 'overdue' => $case->response_due_at?->isPast() && in_array($case->status, ['open', 'reviewing'], true),
        ];
    }

    private function validateEvidence(User $user, array $fileIds): void
    {
        $files = UploadedFile::query()->whereIn('public_id', $fileIds)->get();
        if (count($fileIds) > 9 || $files->count() !== count($fileIds) || $files->contains(fn (UploadedFile $file): bool => $file->owner_user_id !== $user->id || $file->purpose !== 'task_report' || $file->status !== 'clean')) {
            throw ValidationException::withMessages(['evidence_file_ids' => ['举报证据必须属于当前用户、用途正确且通过安全扫描。']]);
        }
    }

    private function responseHours(string $level): int
    {
        return (int) $this->settings->read($level === 'high' ? 'risk.task_high_response_hours' : 'risk.task_normal_response_hours')['value'];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
