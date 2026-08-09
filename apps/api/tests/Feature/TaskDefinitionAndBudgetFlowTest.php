<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Models\RewardBudgetTransaction;
use App\Modules\Task\Models\TaskVersion;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDefinitionAndBudgetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_draft_is_idempotent_and_publish_atomically_locks_every_reward_budget(): void
    {
        $token = $this->adminToken();
        $points = $this->pool($token, '积分池', 'points', 10000, 'points-pool-create-001');
        $wallet = $this->pool($token, '余额池', 'wallet_cash', 100000, 'wallet-pool-create-001');
        $payload = $this->task([$points['id'], $wallet['id']], 5);
        $payload['business_scenario'] = 'user_bounty';
        $payload['project_name'] = '悬赏发布闭环验证';
        $payload['cover_image_url'] = 'https://static.example.com/tasks/cover.png';
        $payload['supported_devices'] = ['android', 'ios'];
        $payload['tags'] = [];
        $payload['virtual_view_count'] = 120;
        $payload['steps'] = [[
            'id' => 'step-1',
            'kind' => 'verification_image',
            'title' => '提交完成凭证',
            'description' => '按示例遮挡非必要个人信息后上传。',
            'input_type' => 'text_image',
            'required' => true,
            'min_files' => 1,
            'max_files' => 3,
        ]];
        $headers = ['Idempotency-Key' => 'task-draft-create-0001'];
        $created = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/tasks', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.business_scenario', 'user_bounty')
            ->assertJsonPath('data.project_name', '悬赏发布闭环验证')
            ->assertJsonPath('data.supported_devices.0', 'android')
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.virtual_view_count', 120)
            ->assertJsonPath('data.steps.0.input_type', 'text_image')
            ->assertJsonPath('data.steps.0.kind', 'verification_image');
        $taskId = $created->json('data.id');
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/tasks', $payload)
            ->assertCreated()->assertJsonPath('data.id', $taskId);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_versions', 1);
        $this->assertDatabaseCount('task_reward_budgets', 0);

        $published = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-publish-action-0001'])->postJson("/api/v1/admin/tasks/{$taskId}/status", [
            'action' => 'publish', 'reason' => '预算校验通过，发布首个任务。',
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(50, $published->json('data.reward_budget_summaries.0.locked_amount'));
        $this->assertSame(500, $published->json('data.reward_budget_summaries.1.locked_amount'));
        $this->assertSame(50, RewardBudgetPool::query()->where('public_id', $points['id'])->value('locked_amount'));
        $this->assertSame(500, RewardBudgetPool::query()->where('public_id', $wallet['id'])->value('locked_amount'));
        $this->assertDatabaseCount('task_reward_budgets', 2);
        $this->assertDatabaseCount('reward_budget_transactions', 4);
        $this->assertDatabaseHas('operation_logs', ['action' => 'task.publish', 'subject_id' => $taskId]);
        $this->getJson("/api/v1/tasks/{$taskId}")->assertOk()->assertJsonPath('data.view_count', 121);

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-pause-action-0001'])->postJson("/api/v1/admin/tasks/{$taskId}/status", [
            'action' => 'pause', 'reason' => '运营暂停新参与。',
        ])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-resume-action-001'])->postJson("/api/v1/admin/tasks/{$taskId}/status", [
            'action' => 'resume', 'reason' => '风险复核完成后恢复。',
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-close-action-0001'])->postJson("/api/v1/admin/tasks/{$taskId}/status", [
            'action' => 'close', 'reason' => '活动结束并释放未分配预算。', 'existing_claim_policy' => 'continue',
        ])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame(0, RewardBudgetPool::query()->where('public_id', $points['id'])->value('locked_amount'));
        $this->assertSame(0, RewardBudgetPool::query()->where('public_id', $wallet['id'])->value('locked_amount'));
        $this->assertDatabaseCount('task_status_changes', 4);
        $this->assertDatabaseCount('reward_budget_transactions', 6);
    }

    public function test_insufficient_component_rolls_back_all_budget_locks(): void
    {
        $token = $this->adminToken('budget-rollback-root');
        $points = $this->pool($token, '小积分池', 'points', 100, 'small-points-pool-001');
        $wallet = $this->pool($token, '小余额池', 'wallet_cash', 100, 'small-wallet-pool-001');
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-budget-fail-0001'])->postJson('/api/v1/admin/tasks', $this->task([$points['id'], $wallet['id']], 5))
            ->assertCreated();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-budget-fail-publish'])->postJson('/api/v1/admin/tasks/'.$created->json('data.id').'/status', [
            'action' => 'publish', 'reason' => '验证预算不足时完整回滚。',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(0, RewardBudgetPool::query()->where('public_id', $points['id'])->value('locked_amount'));
        $this->assertSame(0, RewardBudgetPool::query()->where('public_id', $wallet['id'])->value('locked_amount'));
        $this->assertDatabaseCount('task_reward_budgets', 0);
        $this->assertDatabaseCount('reward_budget_transactions', 2);
    }

    public function test_versions_classify_changes_use_optimistic_lock_and_rollback_by_copy(): void
    {
        $token = $this->adminToken('version-root');
        $points = $this->pool($token, '版本积分池', 'points', 10000, 'version-points-pool-01');
        $wallet = $this->pool($token, '版本余额池', 'wallet_cash', 100000, 'version-wallet-pool-01');
        $payload = $this->task([$points['id'], $wallet['id']], 5);
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-version-create-001'])->postJson('/api/v1/admin/tasks', $payload)->assertCreated();
        $taskId = $created->json('data.id');
        $payload['title'] = '只修改标题的任务';
        $payload['version'] = 1;
        $updated = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-version-update-001'])->putJson("/api/v1/admin/tasks/{$taskId}", $payload)
            ->assertOk()->assertJsonPath('data.task_version.change_type', 'minor')->assertJsonPath('data.version', 2);
        $payload['reward_quota_total'] = 6;
        $payload['version'] = 1;
        $this->withToken($token)->putJson("/api/v1/admin/tasks/{$taskId}", $payload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withToken($token)->getJson("/api/v1/admin/tasks/{$taskId}/versions")->assertOk()->assertJsonCount(2, 'data');
        $rolled = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'task-version-rollback-01'])->postJson("/api/v1/admin/tasks/{$taskId}/versions/1/rollback", [
            'reason' => '恢复初始标题并生成新草稿。', 'current_version' => $updated->json('data.version'),
        ])->assertCreated()->assertJsonPath('data.task_version.change_type', 'rollback')->assertJsonPath('data.task_version.version_no', 3);
        $this->assertSame($this->task([$points['id'], $wallet['id']], 5)['title'], $rolled->json('data.title'));

        $version = TaskVersion::query()->where('task_id', 1)->where('version_no', 1)->firstOrFail();
        $this->expectException(\LogicException::class);
        $version->update(['content' => ['tampered' => true]]);
    }

    public function test_budget_pool_adjustment_is_versioned_idempotent_and_append_only(): void
    {
        $token = $this->adminToken('pool-adjust-root');
        $pool = $this->pool($token, '可调整积分池', 'points', 1000, 'adjust-points-pool-001');
        $headers = ['Idempotency-Key' => 'pool-adjust-increase-001'];
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/reward-budget-pools/'.$pool['id'].'/adjustments', [
            'action' => 'increase', 'amount' => 500, 'reason' => '增加活动积分额度。', 'version' => 1,
        ])->assertOk()->assertJsonPath('data.total_amount', 1500)->assertJsonPath('data.version', 2);
        $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/admin/reward-budget-pools/'.$pool['id'].'/adjustments', [
            'action' => 'increase', 'amount' => 500, 'reason' => '重复请求不重复增加。', 'version' => 1,
        ])->assertOk()->assertJsonPath('data.total_amount', 1500);
        $this->assertDatabaseCount('reward_budget_transactions', 2);

        $transaction = RewardBudgetTransaction::query()->latest('id')->firstOrFail();
        $this->expectException(\LogicException::class);
        $transaction->update(['reason' => 'tampered']);
    }

    public function test_future_task_is_scheduled_and_activated_by_idempotent_command(): void
    {
        $token = $this->adminToken('scheduled-root');
        $points = $this->pool($token, '排期积分池', 'points', 10000, 'scheduled-points-001');
        $wallet = $this->pool($token, '排期余额池', 'wallet_cash', 100000, 'scheduled-wallet-001');
        $payload = $this->task([$points['id'], $wallet['id']], 5);
        $payload['claim_starts_at'] = now()->addDay()->toIso8601String();
        $payload['claim_ends_at'] = now()->addDays(3)->toIso8601String();
        $payload['submit_ends_at'] = now()->addDays(4)->toIso8601String();
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'scheduled-task-create-01'])->postJson('/api/v1/admin/tasks', $payload)->assertCreated();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'scheduled-task-publish'])->postJson('/api/v1/admin/tasks/'.$created->json('data.id').'/status', [
            'action' => 'publish', 'reason' => '发布未来开始的任务。',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->travel(2)->days();
        $this->artisan('tasks:activate-scheduled')->assertExitCode(0);
        $this->withToken($this->adminToken('scheduled-reader'))->getJson('/api/v1/admin/tasks/'.$created->json('data.id'))
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->artisan('tasks:activate-scheduled')->assertExitCode(0);
    }

    public function test_high_wallet_reward_requires_a_different_finance_admin_and_approval_is_idempotent(): void
    {
        $publisherToken = $this->adminToken('high-reward-publisher');
        $wallet = $this->pool($publisherToken, '高额余额池', 'wallet_cash', 1000000, 'high-wallet-pool-0001');
        $points = $this->pool($publisherToken, '高额积分池', 'points', 10000, 'high-points-pool-0001');
        $payload = $this->task([$points['id'], $wallet['id']], 2);
        $payload['rewards'][1]['balance_amount'] = 20001;
        $created = $this->withToken($publisherToken)->withHeaders(['Idempotency-Key' => 'high-task-create-0001'])
            ->postJson('/api/v1/admin/tasks', $payload)->assertCreated();
        $taskId = $created->json('data.id');

        $pending = $this->withToken($publisherToken)->withHeaders(['Idempotency-Key' => 'high-task-publish-0001'])
            ->postJson("/api/v1/admin/tasks/{$taskId}/status", ['action' => 'publish', 'reason' => '申请高额余额奖励发布。'])
            ->assertOk()->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.publish_approval.status', 'pending');
        $this->assertContains('wallet_unit_reward_exceeds_threshold', $pending->json('data.publish_approval.triggered_rules'));
        $this->assertDatabaseCount('task_reward_budgets', 0);

        $this->withToken($publisherToken)->withHeaders(['Idempotency-Key' => 'high-task-self-review'])
            ->postJson("/api/v1/admin/tasks/{$taskId}/publish-approval", ['decision' => 'approve', 'note' => '自己复核。'])
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $reviewerToken = $this->adminToken('high-reward-reviewer');
        $reviewHeaders = ['Idempotency-Key' => 'high-task-review-0001'];
        $this->withToken($reviewerToken)->withHeaders($reviewHeaders)
            ->postJson("/api/v1/admin/tasks/{$taskId}/publish-approval", ['decision' => 'approve', 'note' => '财务预算核验通过。'])
            ->assertOk()->assertJsonPath('data.publish_approval.status', 'approved');
        $this->withToken($reviewerToken)->withHeaders($reviewHeaders)
            ->postJson("/api/v1/admin/tasks/{$taskId}/publish-approval", ['decision' => 'approve', 'note' => '重复请求。'])
            ->assertOk()->assertJsonPath('data.publish_approval.status', 'approved');

        $publishHeaders = ['Idempotency-Key' => 'high-task-final-publish'];
        $this->withToken($publisherToken)->withHeaders($publishHeaders)
            ->postJson("/api/v1/admin/tasks/{$taskId}/status", ['action' => 'publish', 'reason' => '财务复核完成后发布。'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->withToken($publisherToken)->withHeaders($publishHeaders)
            ->postJson("/api/v1/admin/tasks/{$taskId}/status", ['action' => 'publish', 'reason' => '重复发布请求。'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseCount('task_reward_budgets', 2);
    }

    /** @return array<string,mixed> */
    private function pool(string $token, string $name, string $type, int $amount, string $key): array
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/admin/reward-budget-pools', [
            'name' => $name, 'asset_type' => $type, 'total_amount' => $amount, 'reason' => '初始化测试预算池。',
        ])->assertCreated()->json('data');
    }

    /** @return array<string,mixed> */
    private function task(array $poolIds, int $quota): array
    {
        return [
            'visibility' => 'public', 'category' => 'campaign', 'task_type' => 'one_time',
            'participation_mode' => 'claim_and_submit', 'verification_mode' => 'manual', 'title' => '测试任务',
            'description' => '提交真实材料后由平台审核。', 'tags' => ['测试', '活动'],
            'rewards' => [['type' => 'points', 'points' => 10], ['type' => 'wallet_balance', 'balance_amount' => 100]],
            'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $poolIds[0]], ['reward_sequence' => 2, 'budget_pool_id' => $poolIds[1]]],
            'reward_quota_total' => $quota, 'completion_window_hours' => 24, 'submission_limit' => 3,
            'review_deadline_hours' => 48, 'appeal_deadline_days' => 7, 'appeal_limit' => 1,
            'claim_starts_at' => now()->subMinute()->toIso8601String(), 'claim_ends_at' => now()->addDays(5)->toIso8601String(),
            'submit_ends_at' => now()->addDays(7)->toIso8601String(), 'rules' => [],
        ];
    }

    private function adminToken(string $account = 'task-budget-root'): string
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }
}
