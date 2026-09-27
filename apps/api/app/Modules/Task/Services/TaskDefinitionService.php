<?php

namespace App\Modules\Task\Services;

use App\Common\Support\RichTextSanitizer;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskPublishApproval;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskTemplate;
use App\Modules\Task\Models\TaskVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskDefinitionService
{
    public function __construct(
        private readonly RewardBudgetService $budgets,
        private readonly SystemSettingService $settings,
        private readonly RichTextSanitizer $richText,
        private readonly TaskDataBatchService $dataBatches,
    ) {}

    public function create(AdminUser $admin, array $content, string $idempotencyKey, string $channel = 'admin_web'): Task
    {
        $content = $this->richText->normalizeContent($content);
        if ($content['description_text'] === '' && ! str_contains($content['description'], '<img')) {
            throw ValidationException::withMessages(['description' => ['任务说明不能只包含无效或不安全内容。']]);
        }
        $hash = $this->fingerprint($idempotencyKey);
        $existing = Task::query()->where('created_by_admin_id', $admin->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $this->validateDefinition($content);

        return DB::transaction(function () use ($admin, $content, $hash, $channel): Task {
            $task = Task::query()->create(['created_by_admin_id' => $admin->id, 'creation_channel' => $channel, 'idempotency_key_hash' => $hash]);
            TaskVersion::query()->create([
                'task_id' => $task->id, 'version_no' => 1, 'status' => 'draft', 'change_type' => 'initial',
                'changed_fields' => array_keys($content), 'content' => $content, 'created_by_admin_id' => $admin->id,
            ]);

            return $task->fresh();
        });
    }

    public function update(AdminUser $admin, Task $task, array $content, int $expectedVersion): Task
    {
        $content = $this->richText->normalizeContent($content);
        if ($content['description_text'] === '' && ! str_contains($content['description'], '<img')) {
            throw ValidationException::withMessages(['description' => ['任务说明不能只包含无效或不安全内容。']]);
        }
        $this->validateDefinition($content);

        return DB::transaction(function () use ($admin, $task, $content, $expectedVersion): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => ['任务已被其他管理员更新，请刷新后重试。']]);
            }
            $previous = TaskVersion::query()->where('task_id', $locked->id)->where('version_no', $locked->current_version_no)->firstOrFail();
            $changed = $this->changedFields($previous->content, $content);
            if ($changed === []) {
                return $locked;
            }
            $versionNo = $locked->current_version_no + 1;
            TaskVersion::query()->create([
                'task_id' => $locked->id, 'version_no' => $versionNo, 'status' => 'draft',
                'change_type' => $this->changeType($changed), 'source_version_no' => $previous->version_no,
                'changed_fields' => $changed, 'content' => $content, 'created_by_admin_id' => $admin->id,
            ]);
            $this->withdrawPendingApproval($locked);
            // Editing a live task creates an unpublished snapshot, but must not
            // take the already published snapshot offline. New claims continue
            // to use published_version_no while the publisher reviews the draft.
            $keepLiveStatus = $locked->published_version_no !== null
                && in_array($locked->status, ['published', 'scheduled', 'paused'], true);
            $attributes = [
                'current_version_no' => $versionNo,
                'lock_version' => $locked->lock_version + 1,
            ];
            if (! $keepLiveStatus) {
                $attributes['status'] = 'draft';
            }
            $locked->update($attributes);

            return $locked->fresh();
        });
    }

    public function rollback(AdminUser $admin, Task $task, int $sourceVersionNo, int $expectedVersion): Task
    {
        $source = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $sourceVersionNo)->firstOrFail();

        return DB::transaction(function () use ($admin, $task, $source, $expectedVersion): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => ['任务已被其他管理员更新，请刷新后重试。']]);
            }
            $newVersion = $locked->current_version_no + 1;
            TaskVersion::query()->create([
                'task_id' => $locked->id, 'version_no' => $newVersion, 'status' => 'draft', 'change_type' => 'rollback',
                'source_version_no' => $source->version_no, 'changed_fields' => ['rollback'], 'content' => $source->content, 'created_by_admin_id' => $admin->id,
            ]);
            $this->withdrawPendingApproval($locked);
            $locked->update(['current_version_no' => $newVersion, 'status' => 'draft', 'lock_version' => $locked->lock_version + 1]);

            return $locked->fresh();
        });
    }

    public function publish(AdminUser $admin, Task $task, string $reason, string $idempotencyKey): Task
    {
        $key = $this->fingerprint('task-status:'.$task->id.':'.$idempotencyKey);
        if (DB::table('task_status_changes')->where('idempotency_key_hash', $key)->exists()) {
            return $task->fresh();
        }

        return DB::transaction(function () use ($admin, $task, $reason, $idempotencyKey, $key): Task {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            if (! in_array($lockedTask->status, ['draft', 'pending_approval'], true) || $lockedTask->published_version_no !== null) {
                throw ValidationException::withMessages(['status' => ['当前批次仅允许任务首次发布；后续版本切换将在变更审批批次开放。']]);
            }
            $version = TaskVersion::query()->where('task_id', $lockedTask->id)->where('version_no', $lockedTask->current_version_no)->firstOrFail();
            if (! in_array($version->status, ['draft', 'pending_approval', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => ['当前任务版本不能发布，请创建新版本后重试。']]);
            }
            $content = $version->content;
            $this->validateDefinition($content);

            $approvalRules = $this->publishApprovalRules($content);
            if ($approvalRules['triggered_rules'] !== []) {
                $approval = TaskPublishApproval::query()->where('task_version_id', $version->id)->lockForUpdate()->first();
                if ($approval === null) {
                    $approval = TaskPublishApproval::query()->create([
                        'task_id' => $lockedTask->id,
                        'task_version_id' => $version->id,
                        'requested_by_admin_id' => $admin->id,
                        'status' => 'pending',
                        ...$approvalRules,
                    ]);
                }
                if ($approval->status === 'pending') {
                    $from = $lockedTask->status;
                    $version->update(['status' => 'pending_approval']);
                    $lockedTask->update(['status' => 'pending_approval', 'lock_version' => $lockedTask->lock_version + ($from === 'pending_approval' ? 0 : 1)]);
                    $this->recordStatusChange($lockedTask, $admin, $from, 'pending_approval', 'request_publish', $reason, $key);

                    return $lockedTask->fresh();
                }
                if ($approval->status !== 'approved') {
                    throw ValidationException::withMessages(['status' => ['财务复核未通过或已失效，请创建新版本后重新申请。']]);
                }
            }

            $this->dataBatches->lockForPublish($lockedTask, $version);
            $budgetBindings = collect($content['reward_budgets'])->keyBy('reward_sequence');
            $poolIds = $budgetBindings->pluck('budget_pool_id')->unique()->values();
            $pools = RewardBudgetPool::query()->whereIn('public_id', $poolIds)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            if ($pools->count() !== $poolIds->count()) {
                throw ValidationException::withMessages(['reward_budgets' => ['一个或多个预算池不存在。']]);
            }
            foreach ($content['rewards'] as $index => $reward) {
                $sequence = $index + 1;
                $binding = $budgetBindings->get($sequence);
                if ($binding === null) {
                    throw ValidationException::withMessages(['reward_budgets' => ["奖励组件 {$sequence} 缺少预算池。"]]);
                }
                $pool = $pools->get($binding['budget_pool_id']);
                $assetType = $this->assetType($reward);
                if ($pool->asset_type !== $assetType || $pool->status !== 'active') {
                    throw ValidationException::withMessages(['reward_budgets' => ["奖励组件 {$sequence} 的预算池类型或状态无效。"]]);
                }
                $unit = $this->unitAmount($reward);
                $required = $unit * $content['reward_quota_total'];
                if ($assetType === 'coupon') {
                    $templateId = (string) ($reward['coupon_template_id'] ?? '');
                    if ($templateId === '' || $pool->coupon_template_public_id !== $templateId) {
                        throw ValidationException::withMessages(['reward_budgets' => ["奖励组件 {$sequence} 的优惠券模板与预算池绑定不一致。"]]);
                    }
                    $template = CouponTemplate::query()->where('public_id', $templateId)->lockForUpdate()->first();
                    if ($template === null || $template->status !== 'active') {
                        throw ValidationException::withMessages(['rewards' => ["奖励组件 {$sequence} 的优惠券模板不存在或未启用。"]]);
                    }
                    if ($template->total_quantity - $template->issued_quantity < $required) {
                        throw ValidationException::withMessages(['rewards' => ["奖励组件 {$sequence} 的优惠券模板剩余发行量不足。"]]);
                    }
                }
                $available = $pool->total_amount - $pool->locked_amount - $pool->issued_amount;
                if ($available < $required) {
                    throw ValidationException::withMessages(['reward_budgets' => ["奖励组件 {$sequence} 预算不足。"]]);
                }
                $pool->update(['locked_amount' => $pool->locked_amount + $required, 'version' => $pool->version + 1]);
                $taskBudget = TaskRewardBudget::query()->create([
                    'task_id' => $lockedTask->id, 'task_version_id' => $version->id, 'reward_budget_pool_id' => $pool->id,
                    'reward_sequence' => $sequence, 'asset_type' => $assetType, 'unit_amount' => $unit,
                    'max_deliveries_total' => $content['reward_quota_total'], 'max_deliveries_per_cycle' => $content['reward_quota_per_cycle'] ?? null,
                    'locked_amount' => $required,
                ]);
                $this->budgets->record($pool->fresh(), $lockedTask->id, $taskBudget->id, 'task_lock', $required, hash('sha256', 'task-lock:'.$lockedTask->id.':'.$version->version_no.':'.$sequence.':'.$this->fingerprint($idempotencyKey)), $admin, $reason);
            }
            $startsAt = CarbonImmutable::parse($content['claim_starts_at']);
            $status = $startsAt->isFuture() ? 'scheduled' : 'published';
            $from = $lockedTask->status;
            $version->update(['status' => 'published', 'published_at' => now()]);
            $lockedTask->update([
                'status' => $status, 'published_version_no' => $version->version_no,
                'scheduled_at' => $status === 'scheduled' ? $startsAt : null, 'published_at' => $status === 'published' ? now() : null,
                'lock_version' => $lockedTask->lock_version + 1,
            ]);
            $this->recordStatusChange($lockedTask, $admin, $from, $status, 'publish', $reason, $key);

            return $lockedTask->fresh();
        });
    }

    public function reviewPublishApproval(AdminUser $reviewer, Task $task, string $decision, string $note, string $idempotencyKey): Task
    {
        $key = $this->fingerprint('task-publish-review:'.$task->id.':'.$idempotencyKey);
        if (TaskPublishApproval::query()->where('review_idempotency_key_hash', $key)->exists()) {
            return $task->fresh();
        }

        return DB::transaction(function () use ($reviewer, $task, $decision, $note, $key): Task {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $version = TaskVersion::query()->where('task_id', $lockedTask->id)->where('version_no', $lockedTask->current_version_no)->firstOrFail();
            $approval = TaskPublishApproval::query()->where('task_version_id', $version->id)->lockForUpdate()->first();
            if ($approval === null || $approval->status !== 'pending' || $lockedTask->status !== 'pending_approval') {
                throw ValidationException::withMessages(['decision' => ['当前任务没有待处理的发布复核。']]);
            }
            if ($approval->requested_by_admin_id === $reviewer->id) {
                throw ValidationException::withMessages(['decision' => ['高额余额奖励必须由另一位财务管理员复核。']]);
            }

            $approved = $decision === 'approve';
            $approval->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by_admin_id' => $reviewer->id,
                'review_note' => $note,
                'review_idempotency_key_hash' => $key,
                'reviewed_at' => now(),
            ]);
            $version->update(['status' => $approved ? 'approved' : 'rejected']);
            $lockedTask->update([
                'status' => $approved ? 'pending_approval' : 'draft',
                'lock_version' => $lockedTask->lock_version + 1,
            ]);

            return $lockedTask->fresh();
        });
    }

    public function transition(AdminUser $admin, Task $task, string $action, string $reason, string $idempotencyKey, ?string $claimPolicy = null): Task
    {
        $key = $this->fingerprint('task-status:'.$task->id.':'.$idempotencyKey);
        if (DB::table('task_status_changes')->where('idempotency_key_hash', $key)->exists()) {
            return $task->fresh();
        }

        return DB::transaction(function () use ($admin, $task, $action, $reason, $key, $claimPolicy): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $from = $locked->status;
            $to = match ($action) {
                'pause' => in_array($from, ['published', 'scheduled'], true) ? 'paused' : null,
                'resume' => $from === 'paused' ? $this->resumedStatus($locked) : null,
                'close' => in_array($from, ['draft', 'scheduled', 'published', 'paused'], true) ? 'closed' : null,
                'archive' => in_array($from, ['closed', 'expired'], true) ? 'archived' : null,
                default => null,
            };
            if ($to === null) {
                throw ValidationException::withMessages(['action' => ['当前任务状态不允许执行该操作。']]);
            }
            if ($action === 'archive' && TaskRewardBudget::query()->where('task_id', $locked->id)->where('reserved_amount', '>', 0)->exists()) {
                throw ValidationException::withMessages(['action' => ['仍有奖励预留，任务尚不能归档。']]);
            }
            if ($action === 'close') {
                $this->releaseUnallocatedBudgets($admin, $locked, $reason, $key);
            }
            $locked->update([
                'status' => $to, 'closed_at' => $to === 'closed' ? now() : $locked->closed_at,
                'published_at' => $to === 'published' ? ($locked->published_at ?? now()) : $locked->published_at,
                'lock_version' => $locked->lock_version + 1,
            ]);
            DB::table('task_status_changes')->insert([
                'task_id' => $locked->id, 'from_status' => $from, 'to_status' => $to, 'action' => $action,
                'reason' => $reason, 'existing_claim_policy' => $claimPolicy, 'admin_user_id' => $admin->id,
                'idempotency_key_hash' => $key, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function activateScheduled(): int
    {
        return Task::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())
            ->update(['status' => 'published', 'published_at' => now(), 'updated_at' => now()]);
    }

    public function present(Task $task): array
    {
        $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->current_version_no)->firstOrFail();
        $approval = TaskPublishApproval::query()->where('task_version_id', $version->id)->first();
        $budgetVersionId = $version->id;
        $budgets = TaskRewardBudget::query()->where('task_version_id', $budgetVersionId)->get();
        // A live task can have a newer, unpublished editor snapshot. Its
        // budget rows are intentionally not locked yet, so show the currently
        // published budget facts in the management view until that snapshot is
        // approved and published.
        if ($budgets->isEmpty() && $task->published_version_no !== null && $task->published_version_no !== $version->version_no) {
            $publishedVersion = TaskVersion::query()
                ->where('task_id', $task->id)
                ->where('version_no', $task->published_version_no)
                ->first();
            if ($publishedVersion !== null) {
                $budgetVersionId = $publishedVersion->id;
                $budgets = TaskRewardBudget::query()->where('task_version_id', $budgetVersionId)->get();
            }
        }
        $budgets = $budgets->map(fn (TaskRewardBudget $budget) => [
            'id' => $budget->public_id, 'reward_sequence' => $budget->reward_sequence, 'asset_type' => $budget->asset_type,
            'max_deliveries_total' => $budget->max_deliveries_total, 'max_deliveries_per_cycle' => $budget->max_deliveries_per_cycle,
            'locked_amount' => $budget->locked_amount, 'reserved_amount' => $budget->reserved_amount, 'issued_amount' => $budget->issued_amount,
            'released_amount' => $budget->released_amount, 'recovered_amount' => $budget->recovered_amount,
            'available_amount' => max(0, $budget->locked_amount - $budget->reserved_amount - $budget->issued_amount - $budget->released_amount),
            'status' => $budget->status, 'version' => $budget->version,
        ])->values();

        return [
            'id' => $task->public_id, ...$version->content,
            'description_text' => $version->content['description_text'] ?? $this->richText->toPlainText((string) ($version->content['description'] ?? '')),
            'description_format' => $version->content['description_format'] ?? 'rich_text_html',
            'description_schema_version' => $version->content['description_schema_version'] ?? 1,
            'creation_channel' => $task->creation_channel,
            'source_task_template' => $task->source_task_template_id === null ? null : [
                'id' => TaskTemplate::query()->whereKey($task->source_task_template_id)->value('public_id'),
                'version_no' => $task->source_task_template_version_no,
            ],
            'publisher' => $this->presentPublisher($task),
            'status' => $task->status, 'version' => $task->lock_version, 'current_published_version' => $task->published_version_no,
            'has_unpublished_changes' => $task->published_version_no !== null && $task->current_version_no !== $task->published_version_no,
            'historical_claim_count' => 0, 'active_reservation_count' => 0, 'successful_reward_count' => 0,
            'remaining_reward_slots' => $version->content['reward_quota_total'],
            'eligibility' => ['status' => 'eligible', 'message' => '后台预览'], 'can_claim' => $task->status === 'published', 'can_report' => $task->status === 'published',
            'placements' => [],
            'task_version' => ['version_no' => $version->version_no, 'status' => $version->status, 'change_type' => $version->change_type, 'changed_fields' => $version->changed_fields],
            'publish_approval' => $approval === null ? null : [
                'id' => $approval->public_id,
                'status' => $approval->status,
                'triggered_rules' => $approval->triggered_rules,
                'threshold_snapshot' => $approval->threshold_snapshot,
                'requested_by_admin_id' => AdminUser::query()->whereKey($approval->requested_by_admin_id)->value('public_id'),
                'reviewed_by_admin_id' => $approval->reviewed_by_admin_id === null ? null : AdminUser::query()->whereKey($approval->reviewed_by_admin_id)->value('public_id'),
                'review_note' => $approval->review_note,
                'reviewed_at' => $approval->reviewed_at?->toIso8601String(),
            ],
            'reward_budget_bindings' => $version->content['reward_budgets'] ?? [],
            'reward_budgets' => $budgets, 'reward_budget_summaries' => $budgets,
        ];
    }

    /** @return array<string, mixed> */
    private function presentPublisher(Task $task): array
    {
        $publisher = TaskPublisher::query()
            ->with('linkedUser')
            ->where('admin_user_id', $task->created_by_admin_id)
            ->first();

        if ($publisher?->publisher_type === 'user' && $publisher->linkedUser !== null) {
            return [
                'id' => $publisher->public_id,
                'publisher_type' => 'user',
                'display_name' => $publisher->linkedUser->nickname,
                'verification_status' => $publisher->verification_status,
                'publisher_badge' => '用户发布',
            ];
        }

        return [
            'id' => AdminUser::query()->whereKey($task->created_by_admin_id)->value('public_id'),
            'publisher_type' => 'platform_admin',
            'display_name' => '平台任务运营',
            'verification_status' => 'verified',
            'publisher_badge' => '平台发布',
        ];
    }

    public function versionSummaries(Task $task): array
    {
        return $task->versions()->latest('version_no')->get()->map(fn (TaskVersion $version) => [
            'version_no' => $version->version_no, 'change_type' => $version->change_type, 'source_version_no' => $version->source_version_no,
            'status' => $version->status, 'changed_fields' => $version->changed_fields, 'created_at' => $version->created_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
        ])->all();
    }

    /** @param array<string,mixed> $content */
    public function validateTemplateContent(array $content): void
    {
        $runtime = [
            'reward_budgets' => collect($content['rewards'] ?? [])->values()->map(fn ($reward, int $index) => [
                'reward_sequence' => $index + 1,
                'budget_pool_id' => str_repeat('0', 26),
            ])->all(),
            'claim_starts_at' => now()->addMinute()->toIso8601String(),
            'claim_ends_at' => now()->addDays(2)->toIso8601String(),
            'submit_ends_at' => now()->addDays(3)->toIso8601String(),
        ];
        $this->validateDefinition(array_replace($content, $runtime));
    }

    private function validateDefinition(array $content): void
    {
        $starts = CarbonImmutable::parse($content['claim_starts_at']);
        $claimEnds = CarbonImmutable::parse($content['claim_ends_at']);
        $submitEnds = CarbonImmutable::parse($content['submit_ends_at']);
        if (! $starts->lt($claimEnds) || ! $claimEnds->lte($submitEnds)) {
            throw ValidationException::withMessages(['claim_ends_at' => ['时间必须满足领取开始 < 领取截止 ≤ 提交截止。']]);
        }
        $manual = $content['participation_mode'] === 'claim_and_submit';
        if (($manual && $content['verification_mode'] !== 'manual') || (! $manual && $content['verification_mode'] !== 'automatic')) {
            throw ValidationException::withMessages(['verification_mode' => ['参与方式与核验方式不匹配。']]);
        }
        if (in_array($content['task_type'], ['daily', 'weekly', 'monthly', 'repeatable'], true) && empty($content['reward_quota_per_cycle'])) {
            throw ValidationException::withMessages(['reward_quota_per_cycle' => ['周期任务必须设置每周期奖励上限。']]);
        }
        if (count($content['rewards']) !== count($content['reward_budgets'])) {
            throw ValidationException::withMessages(['reward_budgets' => ['每个奖励组件必须且只能绑定一个预算池。']]);
        }
        foreach ($content['rewards'] as $reward) {
            if (($reward['type'] ?? null) === 'coupon' && empty($reward['coupon_template_id'])) {
                throw ValidationException::withMessages(['rewards' => ['优惠券奖励必须指定优惠券模板。']]);
            }
            $this->unitAmount($reward);
        }
        $this->validateSteps($content['steps'] ?? []);
    }

    /** @param array<int,array<string,mixed>> $steps */
    private function validateSteps(array $steps): void
    {
        $ids = [];
        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $id = (string) ($step['id'] ?? '');
            if ($id === '' || isset($ids[$id])) {
                throw ValidationException::withMessages(['steps' => ["第 {$number} 步的标识为空或重复。"]]);
            }
            $ids[$id] = true;
            $kind = (string) ($step['kind'] ?? $this->legacyStepKind((string) ($step['input_type'] ?? 'none')));
            $inputType = (string) ($step['input_type'] ?? 'none');
            $validInput = match ($kind) {
                'link' => $inputType === 'link',
                'verification_image' => in_array($inputType, ['image', 'text_image'], true),
                'form_input' => in_array($inputType, ['text', 'text_image'], true),
                default => $inputType === 'none',
            };
            if (! $validInput) {
                throw ValidationException::withMessages(['steps' => ["第 {$number} 步的类型与提交材料规则不匹配。"]]);
            }
            if ($kind === 'link') {
                $url = (string) ($step['link_url'] ?? '');
                if (! str_starts_with(strtolower($url), 'https://')) {
                    throw ValidationException::withMessages(['steps' => ["第 {$number} 步必须配置 HTTPS 访问地址。"]]);
                }
            }
            if (in_array($kind, ['qr_code', 'image_guide'], true) && empty($step['example_image_url'])) {
                throw ValidationException::withMessages(['steps' => ["第 {$number} 步必须配置展示图片。"]]);
            }
            if ($kind === 'copy_text' && trim((string) ($step['copy_text'] ?? '')) === '') {
                throw ValidationException::withMessages(['steps' => ["第 {$number} 步必须配置可复制内容。"]]);
            }
            if ($kind === 'verification_image') {
                $min = (int) ($step['min_files'] ?? 1);
                $max = (int) ($step['max_files'] ?? 3);
                if ($min > $max) {
                    throw ValidationException::withMessages(['steps' => ["第 {$number} 步的最少上传数量不能大于最多上传数量。"]]);
                }
            }
        }
    }

    private function legacyStepKind(string $inputType): string
    {
        return match ($inputType) {
            'link' => 'link',
            'image', 'text_image' => 'verification_image',
            'text' => 'form_input',
            default => 'confirmation',
        };
    }

    private function resumedStatus(Task $task): string
    {
        $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->published_version_no)->firstOrFail();
        $content = $version->content;
        if (CarbonImmutable::parse($content['claim_ends_at'])->isPast()) {
            throw ValidationException::withMessages(['action' => ['领取期限已结束，不能恢复任务。']]);
        }

        return CarbonImmutable::parse($content['claim_starts_at'])->isFuture() ? 'scheduled' : 'published';
    }

    private function releaseUnallocatedBudgets(AdminUser $admin, Task $task, string $reason, string $key): void
    {
        TaskRewardBudget::query()->where('task_id', $task->id)->lockForUpdate()->get()->each(function (TaskRewardBudget $budget) use ($admin, $task, $reason, $key): void {
            $releasable = max(0, $budget->locked_amount - $budget->reserved_amount - $budget->issued_amount - $budget->released_amount);
            if ($releasable === 0) {
                return;
            }
            $pool = RewardBudgetPool::query()->lockForUpdate()->findOrFail($budget->reward_budget_pool_id);
            $pool->update(['locked_amount' => $pool->locked_amount - $releasable, 'version' => $pool->version + 1]);
            $budget->update(['released_amount' => $budget->released_amount + $releasable, 'status' => 'closed', 'version' => $budget->version + 1]);
            $this->budgets->record($pool->fresh(), $task->id, $budget->id, 'task_release', -$releasable, hash('sha256', $key.':release:'.$budget->reward_sequence), $admin, $reason);
        });
    }

    /** @return array{triggered_rules:array<int,string>,threshold_snapshot:array<string,int>} */
    private function publishApprovalRules(array $content): array
    {
        $unitThreshold = (int) $this->settings->read('tasks.wallet_reward_second_review_cents')['value'];
        $budgetThreshold = (int) $this->settings->read('tasks.wallet_budget_second_review_cents')['value'];
        $walletUnits = collect($content['rewards'])
            ->filter(fn (array $reward): bool => $reward['type'] === 'wallet_balance')
            ->map(fn (array $reward): int => $this->unitAmount($reward));
        $totalBudget = $walletUnits->sum() * (int) $content['reward_quota_total'];
        $triggered = [];
        if ($walletUnits->contains(fn (int $amount): bool => $amount > $unitThreshold)) {
            $triggered[] = 'wallet_unit_reward_exceeds_threshold';
        }
        if ($totalBudget > $budgetThreshold) {
            $triggered[] = 'wallet_total_budget_exceeds_threshold';
        }

        return [
            'triggered_rules' => $triggered,
            'threshold_snapshot' => [
                'wallet_unit_reward_cents' => $unitThreshold,
                'wallet_total_budget_cents' => $budgetThreshold,
                'calculated_wallet_total_budget_cents' => $totalBudget,
            ],
        ];
    }

    private function withdrawPendingApproval(Task $task): void
    {
        $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->current_version_no)->first();
        if ($version === null) {
            return;
        }
        TaskPublishApproval::query()
            ->where('task_version_id', $version->id)
            ->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'review_note' => '任务内容已生成新版本，原发布复核自动失效。', 'updated_at' => now()]);
        if ($version->status === 'pending_approval') {
            $version->update(['status' => 'superseded']);
        }
    }

    private function recordStatusChange(Task $task, AdminUser $admin, string $from, string $to, string $action, string $reason, string $key): void
    {
        DB::table('task_status_changes')->insert([
            'task_id' => $task->id,
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'reason' => $reason,
            'existing_claim_policy' => null,
            'admin_user_id' => $admin->id,
            'idempotency_key_hash' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function unitAmount(array $reward): int
    {
        $amount = match ($reward['type']) {
            'points' => $reward['points'] ?? 0,
            'wallet_balance' => $reward['balance_amount'] ?? 0,
            'coupon' => $reward['quantity'] ?? 1,
            default => 0,
        };
        if (! is_int($amount) || $amount < 1) {
            throw ValidationException::withMessages(['rewards' => ['奖励数量必须为正整数。']]);
        }

        return $amount;
    }

    private function assetType(array $reward): string
    {
        return $reward['type'] === 'wallet_balance' ? 'wallet_cash' : $reward['type'];
    }

    private function changedFields(array $before, array $after): array
    {
        return array_values(array_filter(array_unique([...array_keys($before), ...array_keys($after)]), fn (string $key) => ($before[$key] ?? null) !== ($after[$key] ?? null)));
    }

    private function changeType(array $fields): string
    {
        $major = ['business_scenario', 'supported_devices', 'description', 'steps', 'attachment_rules', 'rewards', 'reward_budgets', 'reward_quota_total', 'reward_quota_per_cycle', 'participation_mode', 'verification_mode', 'progress_target', 'progress_event_type', 'rules', 'claim_starts_at', 'claim_ends_at', 'submit_ends_at', 'completion_window_hours', 'submission_limit', 'review_deadline_hours', 'appeal_deadline_days'];

        return array_intersect($major, $fields) === [] ? 'minor' : 'major';
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
