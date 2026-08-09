<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Services\TaskClaimService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskHallAndClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_hall_only_exposes_published_tasks_and_claim_atomically_reserves_all_rewards_idempotently(): void
    {
        [$token, $task] = $this->publishedTask(2, 'hall-claim-root');
        $draftPools = [$this->pool($token, '草稿积分池', 'points', 1000, 'draft-points-pool-01'), $this->pool($token, '草稿余额池', 'wallet_cash', 10000, 'draft-wallet-pool-01')];
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'draft-task-hidden-001'])->postJson('/api/v1/admin/tasks', $this->task([$draftPools[0]['id'], $draftPools[1]['id']], 2))->assertCreated();

        $hall = $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame($task->public_id, $hall->json('data.items.0.id'));
        $this->assertSame(2, $hall->json('data.items.0.remaining_reward_slots'));
        $this->assertSame(0, $hall->json('data.items.0.view_count'));
        $this->assertSame(0, $hall->json('data.items.0.completed_user_count'));
        $this->assertNull($hall->json('data.items.0.average_completion_minutes'));
        $this->assertNull($hall->json('data.items.0.average_review_minutes'));

        $user = $this->eligibleUser();
        $service = app(TaskClaimService::class);
        $claim = $service->claim($user, $task, 'claim-all-rewards-001');
        $same = $service->claim($user, $task, 'claim-all-rewards-001');
        $this->assertSame($claim->public_id, $same->public_id);
        $this->assertDatabaseCount('task_claims', 1);
        $this->assertDatabaseCount('task_reward_reservations', 2);
        $this->assertDatabaseCount('task_claim_status_changes', 1);
        $this->assertSame([10, 100], TaskRewardBudget::query()->where('task_id', $task->id)->orderBy('reward_sequence')->pluck('reserved_amount')->all());
        $this->getJson('/api/v1/tasks/'.$task->public_id)
            ->assertOk()
            ->assertJsonPath('data.remaining_reward_slots', 1)
            ->assertJsonPath('data.view_count', 1);
    }

    public function test_hall_exposes_privacy_masked_completed_withdrawal_notices(): void
    {
        $user = User::factory()->create(['nickname' => '测试用户', 'phone' => '18612348045']);
        WalletWithdrawal::query()->create([
            'withdrawal_no' => 'WD20260805000001',
            'user_id' => $user->id,
            'requested_amount_cents' => 6000,
            'fee_rate_ppm' => 6000,
            'fee_amount_cents' => 36,
            'received_amount_cents' => 5964,
            'channel' => 'wechat',
            'status' => 'completed',
            'idempotency_key_hash' => hash('sha256', 'withdrawal-notice-test'),
            'completed_at' => now(),
        ]);

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('data.withdrawal_notices.0.user_masked', '186****8045')
            ->assertJsonPath('data.withdrawal_notices.0.withdrawal_amount', 6000);
    }

    public function test_cancel_releases_every_reservation_but_same_cycle_cannot_be_claimed_again(): void
    {
        [, $task] = $this->publishedTask(1, 'claim-cancel-root');
        $user = $this->eligibleUser();
        $service = app(TaskClaimService::class);
        $claim = $service->claim($user, $task, 'claim-cancel-create-1');
        $cancelled = $service->cancel($user, $claim, '用户尚未开始，主动取消。', 'claim-cancel-action-1');
        $same = $service->cancel($user, $claim, '重复取消。', 'claim-cancel-action-1');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->public_id, $same->public_id);
        $this->assertSame([0, 0], TaskRewardBudget::query()->where('task_id', $task->id)->orderBy('reward_sequence')->pluck('reserved_amount')->all());
        $this->assertDatabaseMissing('task_reward_reservations', ['status' => 'reserved']);

        $this->expectException(ValidationException::class);
        $service->claim($user, $task, 'claim-cancel-reclaim-1');
    }

    public function test_full_quota_rejects_next_user_without_partial_reservation(): void
    {
        [, $task] = $this->publishedTask(1, 'claim-full-root');
        $service = app(TaskClaimService::class);
        $service->claim($this->eligibleUser(), $task, 'claim-full-first-01');
        try {
            $service->claim($this->eligibleUser(), $task, 'claim-full-second-1');
            $this->fail('Expected full quota validation error.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('task_claims', 1);
            $this->assertDatabaseCount('task_reward_reservations', 2);
            $this->assertSame([10, 100], TaskRewardBudget::query()->where('task_id', $task->id)->orderBy('reward_sequence')->pluck('reserved_amount')->all());
        }
    }

    public function test_due_claim_expiry_releases_slots_idempotently(): void
    {
        [, $task] = $this->publishedTask(1, 'claim-expire-root');
        $service = app(TaskClaimService::class);
        $claim = $service->claim($this->eligibleUser(), $task, 'claim-expire-create-1');
        $claim->update(['completion_deadline_at' => now()->subMinute()]);
        $this->artisan('tasks:expire-claims')->assertSuccessful();
        $this->artisan('tasks:expire-claims')->assertSuccessful();
        $this->assertSame('expired', $claim->fresh()->status);
        $this->assertSame([0, 0], TaskRewardBudget::query()->where('task_id', $task->id)->orderBy('reward_sequence')->pluck('reserved_amount')->all());
        $this->assertDatabaseCount('task_claim_status_changes', 2);
    }

    public function test_auto_enroll_requires_system_source_and_still_reserves_every_reward(): void
    {
        [, $task] = $this->publishedTask(1, 'auto-enroll-root', 'auto_enroll');
        $service = app(TaskClaimService::class);
        $user = $this->eligibleUser();
        try {
            $service->claim($user, $task, 'auto-enroll-user-attempt');
            $this->fail('Expected user claim to be rejected for auto-enroll tasks.');
        } catch (ValidationException) {
            $claim = $service->claim($user, $task, 'auto-enroll-system-event', 'system');
            $this->assertSame('system', $claim->enrollment_source);
            $this->assertDatabaseCount('task_reward_reservations', 2);
            $this->assertFalse($this->getJson('/api/v1/tasks/'.$task->public_id)->json('data.can_claim'));
        }
    }

    /** @return array{string,Task} */
    private function publishedTask(int $quota, string $account, string $participationMode = 'claim_and_submit'): array
    {
        $token = $this->adminToken($account);
        $points = $this->pool($token, $account.'积分池', 'points', 10000, $account.'-points-pool');
        $wallet = $this->pool($token, $account.'余额池', 'wallet_cash', 100000, $account.'-wallet-pool');
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => $account.'-task-create'])->postJson('/api/v1/admin/tasks', $this->task([$points['id'], $wallet['id']], $quota, $participationMode))->assertCreated();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => $account.'-task-publish'])->postJson('/api/v1/admin/tasks/'.$created->json('data.id').'/status', [
            'action' => 'publish', 'reason' => '发布领取专项测试任务。',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        return [$token, Task::query()->where('public_id', $created->json('data.id'))->firstOrFail()];
    }

    /** @return array<string,mixed> */
    private function pool(string $token, string $name, string $type, int $amount, string $key): array
    {
        return $this->withToken($token)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/admin/reward-budget-pools', [
            'name' => $name, 'asset_type' => $type, 'total_amount' => $amount, 'reason' => '初始化领取测试预算池。',
        ])->assertCreated()->json('data');
    }

    /** @return array<string,mixed> */
    private function task(array $poolIds, int $quota, string $participationMode = 'claim_and_submit'): array
    {
        return [
            'visibility' => 'public', 'category' => 'campaign', 'task_type' => 'one_time',
            'participation_mode' => $participationMode, 'verification_mode' => $participationMode === 'claim_and_submit' ? 'manual' : 'automatic', 'title' => '任务大厅领取测试',
            'description' => '领取时必须同时预留积分和余额奖励。', 'tags' => ['领取'],
            'rewards' => [['type' => 'points', 'points' => 10], ['type' => 'wallet_balance', 'balance_amount' => 100]],
            'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $poolIds[0]], ['reward_sequence' => 2, 'budget_pool_id' => $poolIds[1]]],
            'reward_quota_total' => $quota, 'completion_window_hours' => 24, 'submission_limit' => 3,
            'review_deadline_hours' => 48, 'appeal_deadline_days' => 7, 'appeal_limit' => 1,
            'claim_starts_at' => now()->subMinute()->toIso8601String(), 'claim_ends_at' => now()->addDays(5)->toIso8601String(),
            'submit_ends_at' => now()->addDays(7)->toIso8601String(), 'rules' => [],
        ];
    }

    private function eligibleUser(): User
    {
        return User::factory()->create(['status' => 'active', 'phone_verified_at' => now()]);
    }

    private function adminToken(string $account): string
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
    }
}
