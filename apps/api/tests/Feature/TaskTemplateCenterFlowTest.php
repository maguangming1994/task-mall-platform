<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTemplateVersion;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTemplateCenterFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_versions_copy_status_and_independent_task_draft_flow(): void
    {
        $token = $this->adminToken();
        $content = $this->templateContent();
        $headers = ['Idempotency-Key' => 'template-create-campus-001'];

        $created = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/task-templates', [
            'code' => 'campus-research-basic',
            'name' => '校园调研基础模板',
            'description' => '适用于校园问卷和访谈任务。',
            'content' => $content,
        ])->assertCreated()
            ->assertJsonPath('data.status_label', '启用')
            ->assertJsonPath('data.current_version_no', 1)
            ->assertJsonMissingPath('data.content.reward_budgets')
            ->assertJsonMissingPath('data.content.claim_starts_at');
        $templateId = $created->json('data.id');

        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/task-templates', [
            'code' => 'campus-research-basic', 'name' => '重复请求', 'content' => $content,
        ])->assertCreated()->assertJsonPath('data.id', $templateId);
        $this->assertDatabaseCount('task_templates', 1);

        $content['title'] = '校园访谈任务';
        $updated = $this->withToken($token)->putJson("/api/v1/admin/task-templates/{$templateId}", [
            'version' => 1, 'name' => '校园调研与访谈模板', 'content' => $content,
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.current_version_no', 2);
        $this->withToken($token)->getJson("/api/v1/admin/task-templates/{$templateId}/versions")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.version_no', 2);

        $copy = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'template-copy-campus-001'])
            ->postJson("/api/v1/admin/task-templates/{$templateId}/copy", [
                'code' => 'campus-research-copy', 'name' => '校园调研副本',
            ])->assertCreated()->assertJsonPath('data.current_version_no', 1);
        $this->assertSame($templateId, $copy->json('data.copied_from_template_id'));

        $disabled = $this->withToken($token)->postJson("/api/v1/admin/task-templates/{$templateId}/status", [
            'action' => 'disable', 'version' => $updated->json('data.version'), 'reason' => '暂时停止使用。',
        ])->assertOk()->assertJsonPath('data.status_label', '停用');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'template-create-task-blocked'])
            ->postJson("/api/v1/admin/task-templates/{$templateId}/create-task", ['overrides' => $this->runtimeOverrides('missing')])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->withToken($token)->postJson("/api/v1/admin/task-templates/{$templateId}/status", [
            'action' => 'enable', 'version' => $disabled->json('data.version'), 'reason' => '恢复使用。',
        ])->assertOk()->assertJsonPath('data.status_label', '启用');

        $pool = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'template-points-pool-001'])
            ->postJson('/api/v1/admin/reward-budget-pools', [
                'name' => '模板积分池', 'asset_type' => 'points', 'total_amount' => 10000, 'reason' => '模板测试。',
            ])->assertCreated()->json('data');
        $task = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'template-create-task-draft'])
            ->postJson("/api/v1/admin/task-templates/{$templateId}/create-task", [
                'overrides' => $this->runtimeOverrides($pool['id']),
            ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', '校园访谈任务')
            ->assertJsonPath('data.creation_channel', 'task_template')
            ->assertJsonPath('data.source_task_template.id', $templateId)
            ->assertJsonPath('data.source_task_template.version_no', 2);

        $taskModel = Task::query()->where('public_id', $task->json('data.id'))->firstOrFail();
        $this->assertSame(2, $taskModel->source_task_template_version_no);
        $this->assertDatabaseCount('task_reward_budgets', 0);
        $this->assertDatabaseHas('operation_logs', ['action' => 'task_template.create_task_draft', 'subject_id' => $task->json('data.id')]);

        $version = TaskTemplateVersion::query()->where('task_template_id', $taskModel->source_task_template_id)->where('version_no', 1)->firstOrFail();
        $this->expectException(\LogicException::class);
        $version->update(['content' => ['tampered' => true]]);
    }

    private function templateContent(): array
    {
        return [
            'business_scenario' => 'platform_task', 'project_name' => '校园调研', 'visibility' => 'public',
            'category' => 'research', 'task_type' => 'one_time', 'participation_mode' => 'claim_and_submit',
            'verification_mode' => 'manual', 'title' => '校园调研任务', 'description' => '<p>按步骤完成校园调研并提交材料。</p>',
            'tags' => ['校园', '调研'], 'supported_devices' => ['android', 'ios'],
            'rewards' => [['type' => 'points', 'points' => 20]], 'reward_quota_total' => 50,
            'completion_window_hours' => 24, 'submission_limit' => 3, 'review_deadline_hours' => 48,
            'appeal_deadline_days' => 7, 'appeal_limit' => 1, 'rules' => [], 'steps' => [[
                'id' => 'survey-proof', 'title' => '上传调研凭证', 'kind' => 'verification_image',
                'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 3,
            ]],
        ];
    }

    private function runtimeOverrides(string $poolId): array
    {
        return [
            'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $poolId]],
            'claim_starts_at' => now()->subMinute()->toIso8601String(),
            'claim_ends_at' => now()->addDays(5)->toIso8601String(),
            'submit_ends_at' => now()->addDays(7)->toIso8601String(),
        ];
    }

    private function adminToken(): string
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => 'task-template-root', 'display_name' => '模板管理员', 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => 'task-template-root', 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }
}
