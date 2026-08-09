<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskRewardDelivery;
use App\Modules\Task\Services\TaskClaimService;
use App\Modules\Task\Services\TaskRewardDeliveryService;
use App\Modules\Task\Services\TaskSubmissionService;
use App\Modules\Task\Services\TaskVerificationService;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskSubmissionReviewRewardFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_custom_steps_are_validated_from_the_claim_snapshot(): void
    {
        $steps = [
            ['id' => 'answer', 'kind' => 'form_input', 'title' => '填写订单号', 'description' => '填写已完成订单号。', 'input_type' => 'text', 'required' => true],
            ['id' => 'proof', 'kind' => 'verification_image', 'title' => '上传订单截图', 'description' => '上传清晰订单截图。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
            ['id' => 'confirm', 'kind' => 'confirmation', 'title' => '确认信息真实', 'description' => '确认提交内容真实有效。', 'input_type' => 'none', 'required' => true],
        ];
        [, , $task] = $this->publishedTask('step-schema-submit', [['type' => 'points', 'points' => 10]], 'claim_and_submit', $steps);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'step-schema-claim');
        $file = $this->cleanFile($user, 'step-schema-file');
        $service = app(TaskSubmissionService::class);

        try {
            $service->submit($user, $claim, ['text' => '尝试绕过步骤校验', 'fields' => []], [$file->public_id], 'step-schema-invalid');
            $this->fail('Expected required step validation error.');
        } catch (ValidationException) {
            $submission = $service->submit($user, $claim, ['text' => '按步骤提交', 'fields' => [
                'steps' => ['answer' => 'ORDER-20260805'],
                'step_files' => ['proof' => [$file->public_id]],
                'completed_steps' => ['confirm' => true],
            ]], [$file->public_id], 'step-schema-valid');
        }

        $this->assertSame('submitted', $submission->status);
        $this->assertSame('ORDER-20260805', $submission->content_snapshot['fields']['steps']['answer']);
    }

    public function test_submit_withdraw_and_resubmit_are_idempotent_and_withdraw_does_not_restore_count(): void
    {
        [, , $task] = $this->publishedTask('manual-submit', [['type' => 'points', 'points' => 10]]);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'manual-submit-claim');
        $file = $this->cleanFile($user, 'manual-submit-file');
        $service = app(TaskSubmissionService::class);

        $first = $service->submit($user, $claim, ['text' => '第一次提交', 'fields' => []], [$file->public_id], 'manual-submit-first');
        $same = $service->submit($user, $claim, ['text' => '重复请求', 'fields' => []], [], 'manual-submit-first');
        $this->assertSame($first->public_id, $same->public_id);
        $service->withdraw($user, $first, '发现材料需要更正', 'manual-submit-withdraw');
        $second = $service->submit($user, $claim->fresh(), ['text' => '第二次提交', 'fields' => ['answer' => 'ok']], [], 'manual-submit-second');

        $this->assertSame(2, $second->submission_no);
        $this->assertSame(2, $claim->fresh()->submission_count);
        $this->assertSame('submitted', $claim->fresh()->status);
        $this->assertDatabaseCount('task_submissions', 2);
        $this->assertDatabaseHas('task_submissions', ['id' => $first->id, 'status' => 'withdrawn']);
    }

    public function test_approval_delivers_every_reward_and_settles_reservations_and_budgets_once(): void
    {
        [, $admin, $task] = $this->publishedTask('manual-approve', [
            ['type' => 'points', 'points' => 20], ['type' => 'wallet_balance', 'balance_amount' => 150],
        ]);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'manual-approve-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '材料完整', 'fields' => []], [], 'manual-approve-submit');

        $reviewed = app(TaskSubmissionService::class)->review($admin, $submission, 'approve', null, '审核通过', 'manual-approve-review');
        app(TaskSubmissionService::class)->review($admin, $submission, 'approve', null, '重复审核', 'manual-approve-review');

        $this->assertSame('approved', $reviewed->status);
        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSame('consumed', $claim->fresh()->slot_status);
        $this->assertSame('delivered', $claim->fresh()->reward_status);
        $this->assertDatabaseCount('task_reviews', 1);
        $this->assertDatabaseCount('task_reward_deliveries', 2);
        $this->assertSame(['delivered'], TaskRewardDelivery::query()->pluck('status')->unique()->all());
        $this->assertSame([0, 0], TaskRewardBudget::query()->where('task_id', $task->id)->orderBy('id')->pluck('reserved_amount')->all());
        $this->assertSame([20, 150], TaskRewardBudget::query()->where('task_id', $task->id)->orderBy('id')->pluck('issued_amount')->all());
        $this->assertSame(20, $user->assetAccount()->firstOrFail()->points_available);
        $this->assertSame(150, $user->assetAccount()->firstOrFail()->wallet_available_cents);
    }

    public function test_rejection_requires_reason_and_allows_resubmission_within_limit(): void
    {
        [, $admin, $task] = $this->publishedTask('manual-reject', [['type' => 'points', 'points' => 10]]);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'manual-reject-claim');
        $service = app(TaskSubmissionService::class);
        $submission = $service->submit($user, $claim, ['text' => '证据不清晰', 'fields' => []], [], 'manual-reject-submit');
        try {
            $service->review($admin, $submission, 'reject', null, null, 'manual-reject-invalid');
            $this->fail('Expected reject reason validation error.');
        } catch (ValidationException) {
            $service->review($admin, $submission, 'reject', 'evidence_unclear', '截图内容无法识别，请重新提交。', 'manual-reject-review');
        }

        $this->assertSame('rejected', $claim->fresh()->status);
        $resubmission = $service->submit($user, $claim->fresh(), ['text' => '已补充清晰截图说明', 'fields' => []], [], 'manual-reject-resubmit');
        $this->assertSame(2, $resubmission->submission_no);
        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_trusted_automatic_event_is_idempotent_and_auto_enrolls_then_approves(): void
    {
        [, , $task] = $this->publishedTask('automatic-event', [['type' => 'points', 'points' => 30]], 'auto_enroll');
        $user = $this->eligibleUser();
        $service = app(TaskVerificationService::class);

        $event = $service->consume($user, $task, 'first_task_completed', 'task-engine', 'automatic-event-001', true, ['task_count' => 1]);
        $same = $service->consume($user, $task, 'first_task_completed', 'task-engine', 'automatic-event-001', true, ['task_count' => 99]);

        $this->assertSame($event->public_id, $same->public_id);
        $this->assertDatabaseCount('task_verification_events', 1);
        $this->assertDatabaseCount('task_claims', 1);
        $claim = TaskClaim::query()->firstOrFail();
        $this->assertSame('approved', $claim->status);
        $this->assertNotNull($claim->progress_started_at);
        $this->assertSame('delivered', $claim->reward_status);
        $this->assertSame(30, $user->assetAccount()->firstOrFail()->points_available);
    }

    public function test_failed_coupon_component_does_not_revoke_approval_and_can_be_retried(): void
    {
        [$token, $admin] = $this->admin('coupon-retry');
        $template = CouponTemplate::query()->create([
            'name' => '任务券', 'cash_discount_cents' => 500, 'points_discount' => 0, 'minimum_cash_cents' => 1000,
            'minimum_points' => 0, 'valid_days' => 30, 'total_quantity' => 10, 'status' => 'inactive', 'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', 'coupon-retry-template'),
        ]);
        $pool = $this->pool($token, 'coupon-retry-pool', 'coupon', 5, $template->public_id);
        $task = $this->createAndPublish($token, 'coupon-retry', [['type' => 'coupon', 'coupon_template_id' => $template->public_id]], [$pool['id']], 'claim_and_submit');
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'coupon-retry-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '领取优惠券', 'fields' => []], [], 'coupon-retry-submit');

        app(TaskSubmissionService::class)->review($admin, $submission, 'approve', null, '审核通过', 'coupon-retry-review');
        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSame('delivery_failed', $claim->fresh()->reward_status);
        $this->assertDatabaseHas('task_reward_deliveries', ['status' => 'failed']);

        $template->update(['status' => 'active']);
        $delivery = TaskRewardDelivery::query()->firstOrFail();
        app(TaskRewardDeliveryService::class)->attempt($delivery);
        app(TaskRewardDeliveryService::class)->deliverForClaim($claim->fresh());
        $this->assertSame('delivered', $claim->fresh()->reward_status);
        $this->assertDatabaseCount('user_coupons', 1);
    }

    /** @return array{string,AdminUser,Task} */
    private function publishedTask(string $key, array $rewards, string $mode = 'claim_and_submit', array $steps = []): array
    {
        [$token, $admin] = $this->admin($key);
        $poolIds = [];
        foreach ($rewards as $index => $reward) {
            $type = $reward['type'] === 'wallet_balance' ? 'wallet_cash' : $reward['type'];
            $poolIds[] = $this->pool($token, $key.'-pool-'.$index, $type, 10000)['id'];
        }

        return [$token, $admin, $this->createAndPublish($token, $key, $rewards, $poolIds, $mode, $steps)];
    }

    private function createAndPublish(string $token, string $key, array $rewards, array $poolIds, string $mode, array $steps = []): Task
    {
        $bindings = array_map(fn (string $poolId, int $index): array => ['reward_sequence' => $index + 1, 'budget_pool_id' => $poolId], $poolIds, array_keys($poolIds));
        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-task-create'])->postJson('/api/v1/admin/tasks', [
            'visibility' => 'public', 'category' => 'campaign', 'task_type' => 'one_time', 'participation_mode' => $mode,
            'verification_mode' => $mode === 'claim_and_submit' ? 'manual' : 'automatic', 'title' => '第14批任务-'.$key,
            'description' => '验证提交、审核、核验和奖励发放闭环。', 'tags' => ['第14批'], 'rewards' => $rewards,
            'reward_budgets' => $bindings, 'reward_quota_total' => 5, 'completion_window_hours' => 24, 'submission_limit' => 3,
            'review_deadline_hours' => 48, 'appeal_deadline_days' => 7, 'appeal_limit' => 1,
            'steps' => $steps,
            'claim_starts_at' => now()->subMinute()->toIso8601String(), 'claim_ends_at' => now()->addDays(5)->toIso8601String(),
            'submit_ends_at' => now()->addDays(7)->toIso8601String(), 'rules' => [],
        ])->assertCreated();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-task-publish'])->postJson('/api/v1/admin/tasks/'.$created->json('data.id').'/status', [
            'action' => 'publish', 'reason' => '发布第14批专项测试任务。',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        return Task::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function pool(string $token, string $key, string $type, int $amount, ?string $couponTemplateId = null): array
    {
        $body = ['name' => $key, 'asset_type' => $type, 'total_amount' => $amount, 'reason' => '初始化专项测试预算池。'];
        if ($couponTemplateId !== null) {
            $body['coupon_template_id'] = $couponTemplateId;
        }

        return $this->withToken($token)->withHeaders(['Idempotency-Key' => $key.'-budget-key'])->postJson('/api/v1/admin/reward-budget-pools', $body)->assertCreated()->json('data');
    }

    /** @return array{string,AdminUser} */
    private function admin(string $account): array
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');

        return [$token, $admin];
    }

    private function eligibleUser(): User
    {
        return User::factory()->create(['status' => 'active', 'phone_verified_at' => now()]);
    }

    private function cleanFile(User $user, string $key): UploadedFile
    {
        return UploadedFile::query()->create([
            'owner_user_id' => $user->id, 'purpose' => 'task_submission', 'original_name' => 'evidence.png', 'extension' => 'png',
            'declared_mime' => 'image/png', 'detected_mime' => 'image/png', 'declared_size' => 100, 'actual_size' => 100,
            'sha256' => hash('sha256', $key), 'disk' => 'local', 'quarantine_path' => 'tests/'.$key.'.png', 'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', $key), 'ticket_expires_at' => now()->addHour(), 'scanned_at' => now(),
        ]);
    }
}
