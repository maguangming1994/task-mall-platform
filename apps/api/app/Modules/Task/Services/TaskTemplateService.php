<?php

namespace App\Modules\Task\Services;

use App\Common\Support\RichTextSanitizer;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTemplate;
use App\Modules\Task\Models\TaskTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TaskTemplateService
{
    private const RUNTIME_FIELDS = ['reward_budgets', 'claim_starts_at', 'claim_ends_at', 'submit_ends_at'];

    public function __construct(
        private readonly TaskDefinitionService $tasks,
        private readonly RichTextSanitizer $richText,
    ) {}

    public function create(AdminUser $admin, array $attributes, array $content, string $idempotencyKey): TaskTemplate
    {
        $hash = hash('sha256', $idempotencyKey);
        $existing = TaskTemplate::query()->where('created_by_admin_id', $admin->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (TaskTemplate::query()->where('code', $attributes['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['模板编码已存在，请更换后重试。']]);
        }
        $content = $this->prepareContent($content);

        return DB::transaction(function () use ($admin, $attributes, $content, $hash): TaskTemplate {
            $template = TaskTemplate::query()->create([
                ...$attributes,
                'category' => $attributes['category'] ?? ($content['category'] ?? null),
                'created_by_admin_id' => $admin->id,
                'updated_by_admin_id' => $admin->id,
                'idempotency_key_hash' => $hash,
            ]);
            TaskTemplateVersion::query()->create([
                'task_template_id' => $template->id,
                'version_no' => 1,
                'changed_fields' => array_keys($content),
                'content' => $content,
                'created_by_admin_id' => $admin->id,
            ]);

            return $template->fresh();
        });
    }

    public function update(AdminUser $admin, TaskTemplate $template, array $attributes, ?array $content, int $expectedVersion): TaskTemplate
    {
        $prepared = $content === null ? null : $this->prepareContent($content);

        return DB::transaction(function () use ($admin, $template, $attributes, $prepared, $expectedVersion): TaskTemplate {
            $locked = TaskTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => ['模板已被其他管理员更新，请刷新后重试。']]);
            }
            $changed = [];
            foreach (['name', 'description', 'category'] as $field) {
                if (array_key_exists($field, $attributes) && $locked->{$field} !== $attributes[$field]) {
                    $changed[] = $field;
                }
            }
            if ($prepared !== null) {
                $previous = $this->currentVersion($locked);
                $contentFields = $this->changedFields($previous->content, $prepared);
                if ($contentFields !== []) {
                    $versionNo = $locked->current_version_no + 1;
                    TaskTemplateVersion::query()->create([
                        'task_template_id' => $locked->id,
                        'version_no' => $versionNo,
                        'source_version_no' => $previous->version_no,
                        'changed_fields' => $contentFields,
                        'content' => $prepared,
                        'created_by_admin_id' => $admin->id,
                    ]);
                    $attributes['current_version_no'] = $versionNo;
                    $changed[] = 'content';
                }
            }
            if ($changed !== []) {
                $locked->update([...$attributes, 'updated_by_admin_id' => $admin->id, 'lock_version' => $locked->lock_version + 1]);
            }

            return $locked->fresh();
        });
    }

    public function copy(AdminUser $admin, TaskTemplate $source, string $code, string $name, string $idempotencyKey): TaskTemplate
    {
        $copy = $this->create($admin, [
            'code' => $code,
            'name' => $name,
            'description' => $source->description,
            'category' => $source->category,
            'copied_from_task_template_id' => $source->id,
        ], $this->currentVersion($source)->content, $idempotencyKey);

        return $copy;
    }

    public function changeStatus(AdminUser $admin, TaskTemplate $template, string $action, int $expectedVersion): TaskTemplate
    {
        return DB::transaction(function () use ($admin, $template, $action, $expectedVersion): TaskTemplate {
            $locked = TaskTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => ['模板已被其他管理员更新，请刷新后重试。']]);
            }
            $status = $action === 'enable' ? 'active' : 'inactive';
            if ($locked->status !== $status) {
                $locked->update(['status' => $status, 'updated_by_admin_id' => $admin->id, 'lock_version' => $locked->lock_version + 1]);
            }

            return $locked->fresh();
        });
    }

    public function createTaskDraft(AdminUser $admin, TaskTemplate $template, array $overrides, string $idempotencyKey): Task
    {
        if ($template->status !== 'active') {
            throw ValidationException::withMessages(['template' => ['已停用的任务模板不能创建任务草稿。']]);
        }
        $version = $this->currentVersion($template);
        $content = array_replace($version->content, $overrides);
        $task = $this->tasks->create($admin, $content, 'task-template:'.$template->public_id.':'.$idempotencyKey, 'task_template');
        if ($task->source_task_template_id === null) {
            $task->update([
                'source_task_template_id' => $template->id,
                'source_task_template_version_no' => $version->version_no,
            ]);
        }

        return $task->fresh();
    }

    public function present(TaskTemplate $template, bool $withContent = true): array
    {
        $version = $this->currentVersion($template);

        return array_filter([
            'id' => $template->public_id,
            'code' => $template->code,
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'status' => $template->status,
            'status_label' => $template->status === 'active' ? '启用' : '停用',
            'version' => $template->lock_version,
            'current_version_no' => $template->current_version_no,
            'copied_from_template_id' => $template->copied_from_task_template_id === null ? null : TaskTemplate::query()->whereKey($template->copied_from_task_template_id)->value('public_id'),
            'content' => $withContent ? $version->content : null,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ], fn ($value, $key) => $key === 'content' ? $withContent : true, ARRAY_FILTER_USE_BOTH);
    }

    public function versions(TaskTemplate $template): array
    {
        return $template->versions()->latest('version_no')->get()->map(fn (TaskTemplateVersion $version) => [
            'version_no' => $version->version_no,
            'source_version_no' => $version->source_version_no,
            'changed_fields' => $version->changed_fields,
            'content' => $version->content,
            'created_at' => $version->created_at?->toIso8601String(),
        ])->all();
    }

    private function currentVersion(TaskTemplate $template): TaskTemplateVersion
    {
        return TaskTemplateVersion::query()->where('task_template_id', $template->id)->where('version_no', $template->current_version_no)->firstOrFail();
    }

    private function prepareContent(array $content): array
    {
        foreach (self::RUNTIME_FIELDS as $field) {
            unset($content[$field]);
        }
        $validator = Validator::make($content, [
            'visibility' => ['required', 'in:public,targeted_private'],
            'category' => ['required', 'in:onboarding,daily,community,commerce,research,campaign'],
            'task_type' => ['required', 'in:one_time,daily,weekly,monthly,milestone,repeatable'],
            'progress_target' => ['sometimes', 'integer', 'min:1'],
            'progress_event_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'participation_mode' => ['required', 'in:claim_and_submit,claim_and_auto_verify,auto_enroll'],
            'verification_mode' => ['required', 'in:manual,automatic'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:200000'],
            'rewards' => ['required', 'array', 'min:1'],
            'rewards.*.type' => ['required', 'in:points,coupon,wallet_balance'],
            'rewards.*.points' => ['sometimes', 'integer', 'min:1'],
            'rewards.*.balance_amount' => ['sometimes', 'integer', 'min:1'],
            'rewards.*.coupon_template_id' => ['sometimes', 'string', 'size:26'],
            'rewards.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'reward_quota_total' => ['required', 'integer', 'min:1'],
            'reward_quota_per_cycle' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'completion_window_hours' => ['required', 'integer', 'min:1'],
            'submission_limit' => ['required', 'integer', 'min:1'],
            'review_deadline_hours' => ['required', 'integer', 'min:1'],
            'appeal_deadline_days' => ['required', 'integer', 'min:1'],
            'appeal_limit' => ['required', 'integer', 'min:0'],
            'steps' => ['sometimes', 'array', 'max:20'],
            'steps.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'steps.*.title' => ['required', 'string', 'max:100'],
            'steps.*.input_type' => ['required', 'in:none,text,image,text_image,link'],
            'steps.*.required' => ['required', 'boolean'],
        ], [], ['title' => '任务标题', 'description' => '任务说明', 'rewards' => '奖励结构', 'steps' => '任务步骤']);
        $validator->validate();
        $manual = $content['participation_mode'] === 'claim_and_submit';
        if (($manual && $content['verification_mode'] !== 'manual') || (! $manual && $content['verification_mode'] !== 'automatic')) {
            throw ValidationException::withMessages(['verification_mode' => ['参与方式与核验方式不匹配。']]);
        }
        $content = $this->richText->normalizeContent($content);
        if ($content['description_text'] === '' && ! str_contains($content['description'], '<img')) {
            throw ValidationException::withMessages(['description' => ['任务说明不能只包含无效或不安全内容。']]);
        }
        $this->tasks->validateTemplateContent($content);

        return $content;
    }

    private function changedFields(array $before, array $after): array
    {
        return collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->filter(fn (string $key) => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->values()->all();
    }
}
