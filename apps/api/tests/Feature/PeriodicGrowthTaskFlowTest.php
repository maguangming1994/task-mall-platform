<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Services\TaskClaimService;
use App\Modules\Task\Services\TaskVerificationService;
use App\Modules\Task\Support\TaskCycle;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodicGrowthTaskFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_daily_progress_is_idempotent_rewards_once_and_resets_in_the_next_cycle(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 10:00:00 Asia/Shanghai');
        $task = $this->publishedAutomaticTask('daily-growth', 'daily', 3, 2);
        $user = User::factory()->create(['status' => 'active', 'phone_verified_at' => now()]);
        $service = app(TaskVerificationService::class);

        $first = $service->consume($user, $task, 'campus_check_in', 'growth-engine', 'check-in-1', false, ['delta' => 1]);
        $same = $service->consume($user, $task, 'campus_check_in', 'growth-engine', 'check-in-1', false, ['delta' => 99]);
        $second = $service->consume($user, $task, 'campus_check_in', 'growth-engine', 'check-in-2', false, ['delta' => 2]);

        $claim = TaskClaim::query()->firstOrFail();
        $this->assertSame($first->id, $same->id);
        $this->assertSame(1, $first->progress_after);
        $this->assertSame(3, $second->progress_after);
        $this->assertSame('2026-08-13', $claim->cycle_key);
        $this->assertSame(3, $claim->progress_current);
        $this->assertSame('approved', $claim->status);
        $this->assertSame('delivered', $claim->reward_status);
        $this->assertSame(2, $user->assetAccount()->firstOrFail()->points_available);
        $this->assertDatabaseCount('task_verification_events', 2);
        $this->assertDatabaseCount('task_reward_deliveries', 1);

        CarbonImmutable::setTestNow('2026-08-14 10:00:00 Asia/Shanghai');
        $next = $service->consume($user, $task, 'campus_check_in', 'growth-engine', 'check-in-next-day', false, ['delta' => 1]);
        $this->assertSame(1, $next->progress_after);
        $this->assertDatabaseCount('task_claims', 2);
        $this->assertDatabaseHas('task_claims', ['cycle_key' => '2026-08-14', 'progress_current' => 1, 'status' => 'claimed']);
        $this->assertSame(2, $user->assetAccount()->firstOrFail()->points_available);
    }

    public function test_monthly_and_milestone_cycle_keys_have_the_expected_reset_semantics(): void
    {
        $at = CarbonImmutable::parse('2026-08-31 23:00:00', 'Asia/Shanghai');
        $this->assertSame('2026-08', TaskCycle::resolve('monthly', $at)['key']);
        $this->assertSame('2026-09', TaskCycle::resolve('monthly', $at->addHours(2))['key']);
        $this->assertSame('milestone', TaskCycle::resolve('milestone', $at)['key']);
        $this->assertSame('milestone', TaskCycle::resolve('milestone', $at->addYears(10))['key']);
    }

    public function test_claim_api_exposes_cycle_window_and_progress_instance(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 10:00:00 Asia/Shanghai');
        $task = $this->publishedAutomaticTask('weekly-growth', 'weekly', 5, 3, 'claim_and_auto_verify');
        $user = User::factory()->create(['status' => 'active', 'phone_verified_at' => now()]);

        $claim = app(TaskClaimService::class)->claim($user, $task, 'weekly-user-claim');
        $presented = app(TaskClaimService::class)->presentClaim($claim->fresh());

        $this->assertSame('2026-W33', $presented['cycle_key']);
        $this->assertNotNull($presented['cycle_starts_at']);
        $this->assertNotNull($presented['cycle_ends_at']);
        $this->assertSame(['current' => 0, 'target' => 5, 'percent' => 0, 'last_progress_at' => null], $presented['progress']);
    }

    private function publishedAutomaticTask(string $key, string $taskType, int $target, int $points, string $mode = 'auto_enroll'): Task
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $key, 'display_name' => $key, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $key, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');
        $pool = $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-pool'])->postJson('/api/v1/admin/reward-budget-pools', [
            'name' => $key, 'asset_type' => 'points', 'total_amount' => 1000, 'reason' => '周期成长任务自动化测试预算。',
        ])->assertCreated()->json('data');

        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-create'])->postJson('/api/v1/admin/tasks', [
            'visibility' => 'public', 'category' => 'daily', 'task_type' => $taskType, 'participation_mode' => $mode,
            'verification_mode' => 'automatic', 'progress_target' => $target, 'progress_event_type' => 'campus_check_in',
            'title' => '周期成长任务 '.$key, 'description' => '通过可信服务端事件累计进度。', 'tags' => ['周期成长'],
            'rewards' => [['type' => 'points', 'points' => $points]],
            'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $pool['id']]],
            'reward_quota_total' => 100, 'reward_quota_per_cycle' => 20, 'completion_window_hours' => 240,
            'submission_limit' => 1, 'review_deadline_hours' => 24, 'appeal_deadline_days' => 7, 'appeal_limit' => 0,
            'steps' => [], 'claim_starts_at' => now()->subDay()->toIso8601String(), 'claim_ends_at' => now()->addMonths(2)->toIso8601String(),
            'submit_ends_at' => now()->addMonths(3)->toIso8601String(), 'rules' => [],
        ])->assertCreated();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-publish'])->postJson('/api/v1/admin/tasks/'.$created->json('data.id').'/status', [
            'action' => 'publish', 'reason' => '发布周期成长任务测试版本。',
        ])->assertOk();

        return Task::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }
}
