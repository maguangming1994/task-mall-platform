<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\PointTransaction;
use App\Modules\Growth\Services\ReferralService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskRiskCase;
use App\Modules\Task\Services\TaskAppealService;
use App\Modules\Task\Services\TaskClaimService;
use App\Modules\Task\Services\TaskRiskService;
use App\Modules\Task\Services\TaskSubmissionService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskAppealRiskReferralPublisherFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_appeal_requires_a_different_reviewer_and_approval_delivers_reward(): void
    {
        [$publisherToken, $publisher, $task] = $this->publishedTask('appeal-approve');
        $original = $this->admin('appeal-original', 'task_reviewer');
        $different = $this->admin('appeal-different', 'task_reviewer');
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'appeal-approve-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '需要补充解释', 'fields' => []], [], 'appeal-approve-submit');
        app(TaskSubmissionService::class)->review($original, $submission, 'reject', 'requirements_not_met', '暂不符合任务要求', 'appeal-approve-reject');
        $claim->update(['submission_count' => 3]);
        $appeal = app(TaskAppealService::class)->create($user, $claim->fresh(), '补充说明后申请复核', [], 'appeal-approve-create');

        try {
            app(TaskAppealService::class)->review($original, $appeal, 'approve', '原审核人尝试自审', 'appeal-approve-self');
            $this->fail('Expected reviewer separation validation error.');
        } catch (ValidationException) {
            app(TaskAppealService::class)->review($different, $appeal, 'approve', '异人复核确认完成', 'appeal-approve-review');
        }

        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSame('delivered', $claim->fresh()->reward_status);
        $this->assertSame('approved', $appeal->fresh()->status);
        $this->assertNotSame($original->id, $appeal->fresh()->reviewer_admin_id);
    }

    public function test_rejected_appeal_releases_slot_and_all_reward_reservations(): void
    {
        [, , $task] = $this->publishedTask('appeal-reject');
        $original = $this->admin('appeal-reject-original', 'task_reviewer');
        $different = $this->admin('appeal-reject-different', 'task_reviewer');
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'appeal-reject-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '不完整材料', 'fields' => []], [], 'appeal-reject-submit');
        app(TaskSubmissionService::class)->review($original, $submission, 'reject', 'evidence_unclear', '材料无法核验', 'appeal-reject-first');
        $claim->update(['submission_count' => 3]);
        $appeal = app(TaskAppealService::class)->create($user, $claim->fresh(), '请求再次核验', [], 'appeal-reject-create');
        app(TaskAppealService::class)->review($different, $appeal, 'reject', '复核后仍无法证实完成', 'appeal-reject-final');

        $this->assertSame('appeal_rejected', $claim->fresh()->status);
        $this->assertSame('released', $claim->fresh()->slot_status);
        $this->assertDatabaseMissing('task_reward_reservations', ['task_claim_id' => $claim->id, 'status' => 'reserved']);
    }

    public function test_duplicate_submission_opens_risk_case_blocks_reward_and_clear_restores_review(): void
    {
        [, , $task] = $this->publishedTask('risk-duplicate');
        $reviewer = $this->admin('risk-reviewer', 'task_reviewer');
        $riskAdmin = $this->admin('risk-admin', 'task_reviewer');
        $firstUser = $this->eligibleUser();
        $secondUser = $this->eligibleUser();
        $firstClaim = app(TaskClaimService::class)->claim($firstUser, $task, 'risk-first-claim');
        $secondClaim = app(TaskClaimService::class)->claim($secondUser, $task, 'risk-second-claim');
        app(TaskSubmissionService::class)->submit($firstUser, $firstClaim, ['text' => '完全相同的批量提交材料', 'fields' => []], [], 'risk-first-submit');
        $duplicate = app(TaskSubmissionService::class)->submit($secondUser, $secondClaim, ['text' => '完全相同的批量提交材料', 'fields' => []], [], 'risk-second-submit');
        $case = TaskRiskCase::query()->where('task_claim_id', $secondClaim->id)->firstOrFail();

        try {
            app(TaskSubmissionService::class)->review($reviewer, $duplicate, 'approve', null, '先尝试通过', 'risk-block-review');
            $this->fail('Expected open risk case to block approval.');
        } catch (ValidationException) {
            app(TaskRiskService::class)->review($riskAdmin, $case, 'clear', ['restore'], '人工核查确认不是作弊', 'risk-clear-case');
            app(TaskSubmissionService::class)->review($reviewer, $duplicate, 'approve', null, '风险解除后通过', 'risk-allow-review');
        }

        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertSame('approved', $secondClaim->fresh()->status);
        $this->assertDatabaseHas('task_risk_actions', ['task_risk_case_id' => $case->id, 'action' => 'restore']);
    }

    public function test_high_risk_report_is_idempotent_and_can_pause_claiming(): void
    {
        [, , $task] = $this->publishedTask('risk-report');
        $user = $this->eligibleUser();
        $admin = $this->admin('risk-report-admin', 'task_reviewer');
        $risk = app(TaskRiskService::class);
        $report = $risk->report($user, $task, 'fraud', '任务疑似诱导站外付款', [], 'risk-report-create');
        $same = $risk->report($user, $task, 'fraud', '重复请求', [], 'risk-report-create');
        $this->assertSame($report->public_id, $same->public_id);
        $case = TaskRiskCase::query()->findOrFail($report->task_risk_case_id);
        $this->assertTrue($case->reward_frozen);
        $this->assertTrue($case->response_due_at->lte(now()->addHours(2)->addMinute()));

        $risk->review($admin, $case, 'confirm_violation', ['stop_claiming', 'freeze_reward'], '确认高风险，暂停新领取', 'risk-report-review');
        $this->assertSame('paused', $task->fresh()->status);
        $this->assertDatabaseCount('task_reports', 1);
    }

    public function test_referral_requires_first_task_and_first_cash_order_then_rewards_once(): void
    {
        [, , $task] = $this->publishedTask('referral-milestones');
        $referrer = $this->eligibleUser();
        $referred = $this->eligibleUser();
        $referrals = app(ReferralService::class);
        $referral = $referrals->bind($referred, $referrals->code($referrer));
        $claim = app(TaskClaimService::class)->claim($referred, $task, 'referral-claim');
        $submission = app(TaskSubmissionService::class)->submit($referred, $claim, ['text' => '完成首个任务', 'fields' => []], [], 'referral-submit');
        app(TaskSubmissionService::class)->review($this->admin('referral-reviewer', 'task_reviewer'), $submission, 'approve', null, '任务通过', 'referral-review');

        $this->assertNotNull($referral->fresh()->first_task_completed_at);
        $this->assertNull($referral->fresh()->rewarded_at);
        $referrals->recordFirstCashOrder($referred, '01ORDERREFERRAL0000000001', 100, 'completed');
        $referrals->recordFirstCashOrder($referred, '01ORDERREFERRAL0000000002', 100, 'completed');
        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertSame(500, $referrer->assetAccount()->firstOrFail()->points_available);
        $this->assertSame(1, PointTransaction::query()->where('user_id', $referrer->id)->count());
    }

    public function test_publisher_session_is_isolated_and_permission_revocation_is_immediate(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $platform = AdminUser::query()->create(['account' => 'front-publisher', 'display_name' => '前端发布管理员', 'password' => 'AdminPassword12345!']);
        $platformRole = Role::query()->where('code', 'platform_admin')->firstOrFail();
        $platform->roles()->attach($platformRole->id);
        $response = $this->postJson('/api/v1/task-publisher/session', ['account' => 'front-publisher', 'password' => 'AdminPassword12345!'])
            ->assertCreated()->assertJsonPath('data.publisher.publisher_type', 'platform_admin');
        $token = $response->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/task-publisher/profile')->assertOk()->assertJsonPath('data.can_publish', true);
        $this->withToken($token)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $platform->roles()->detach($platformRole->id);
        $this->withToken($token)->getJson('/api/v1/task-publisher/profile')->assertForbidden();

        $super = $this->admin('publisher-super-only', 'super_admin');
        $this->postJson('/api/v1/task-publisher/session', ['account' => $super->account, 'password' => 'AdminPassword12345!'])->assertForbidden();
    }

    /** @return array{string,AdminUser,Task} */
    private function publishedTask(string $key): array
    {
        [$token, $publisher] = $this->adminWithToken($key.'-publisher', 'super_admin');
        $pool = $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-pool-create'])->postJson('/api/v1/admin/reward-budget-pools', [
            'name' => $key.'积分池', 'asset_type' => 'points', 'total_amount' => 10000, 'reason' => '第15批专项测试预算',
        ])->assertCreated()->json('data');
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-task-create'])->postJson('/api/v1/admin/tasks', [
            'visibility' => 'public', 'category' => 'campaign', 'task_type' => 'one_time', 'participation_mode' => 'claim_and_submit', 'verification_mode' => 'manual',
            'title' => '第15批-'.$key, 'description' => '申诉风控推荐发布者专项测试', 'tags' => ['第15批'],
            'rewards' => [['type' => 'points', 'points' => 10]], 'reward_budgets' => [['reward_sequence' => 1, 'budget_pool_id' => $pool['id']]],
            'reward_quota_total' => 10, 'completion_window_hours' => 24, 'submission_limit' => 3, 'review_deadline_hours' => 48, 'appeal_deadline_days' => 7, 'appeal_limit' => 1,
            'claim_starts_at' => now()->subMinute()->toIso8601String(), 'claim_ends_at' => now()->addDays(5)->toIso8601String(), 'submit_ends_at' => now()->addDays(7)->toIso8601String(), 'rules' => [],
        ])->assertCreated();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-task-publish'])->postJson('/api/v1/admin/tasks/'.$created->json('data.id').'/status', ['action' => 'publish', 'reason' => '发布第15批专项任务'])->assertOk();

        return [$token, $publisher, Task::query()->where('public_id', $created->json('data.id'))->firstOrFail()];
    }

    private function admin(string $account, string $role): AdminUser
    {
        return $this->adminWithToken($account, $role)[1];
    }

    /** @return array{string,AdminUser} */
    private function adminWithToken(string $account, string $role): array
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');

        return [$token, $admin];
    }

    private function eligibleUser(): User
    {
        return User::factory()->create(['status' => 'active', 'phone_verified_at' => now()]);
    }
}
