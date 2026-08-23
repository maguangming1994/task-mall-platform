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
use App\Modules\Task\Services\TaskSubmissionService;
use App\Modules\Task\Services\TaskVerificationService;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame('under_review', $claim->fresh()->execution_status);
        $this->assertSame('queued', $claim->fresh()->review_status);
        $this->assertDatabaseCount('task_submissions', 2);
        $this->assertDatabaseHas('task_submissions', ['id' => $first->id, 'status' => 'withdrawn']);
    }

    public function test_reviewer_can_preview_only_clean_files_attached_to_the_selected_submission(): void
    {
        Storage::fake('local');
        [$token, , $task] = $this->publishedTask('submission-file-preview', [['type' => 'points', 'points' => 10]]);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'submission-file-preview-claim');
        $file = $this->cleanFile($user, 'submission-file-preview-attached');
        $unrelated = $this->cleanFile($user, 'submission-file-preview-unrelated');
        Storage::disk('local')->put($file->quarantine_path, 'clean-image-content');
        Storage::disk('local')->put($unrelated->quarantine_path, 'unrelated-image-content');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '已提交审核凭证', 'fields' => []], [$file->public_id], 'submission-file-preview-submit');

        $this->withToken($token)
            ->get('/api/v1/admin/task-submissions/'.$submission->public_id.'/files/'.$file->public_id)
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertHeader('cache-control', 'no-store, private');

        $this->withToken($token)
            ->get('/api/v1/admin/task-submissions/'.$submission->public_id.'/files/'.$unrelated->public_id)
            ->assertNotFound();
    }

    public function test_opening_a_submission_starts_review_and_the_reviewing_queue_keeps_it_visible(): void
    {
        [$token, , $task] = $this->publishedTask('start-review-queue', [['type' => 'points', 'points' => 10]]);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'start-review-queue-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '等待审核', 'fields' => []], [], 'start-review-queue-submit');

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'start-review-queue-open'])
            ->postJson('/api/v1/admin/task-submissions/'.$submission->public_id.'/start-review')
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewing');

        $this->assertSame('reviewing', $claim->fresh()->status);
        $this->assertSame('under_review', $claim->fresh()->execution_status);
        $this->assertSame('reviewing', $claim->fresh()->review_status);
        $this->withToken($token)->getJson('/api/v1/admin/task-submissions?status=reviewing')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $submission->public_id)
            ->assertJsonPath('data.items.0.status', 'reviewing')
            ->assertJsonPath('data.items.0.execution_status', 'under_review')
            ->assertJsonPath('data.items.0.execution_status_label', '审核中')
            ->assertJsonPath('data.items.0.review_status', 'reviewing')
            ->assertJsonPath('data.items.0.review_status_label', '审核中');

        $this->withToken($token)->getJson('/api/v1/admin/task-submissions?execution_status=under_review&review_status=reviewing')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_review_lock_can_be_released_and_transferred_only_by_its_owner(): void
    {
        [$ownerToken, $owner, $task] = $this->publishedTask('review-lock', [['type' => 'points', 'points' => 10]]);
        [$otherToken, $other] = $this->admin('review-lock-other');
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'review-lock-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '等待审核', 'fields' => []], [], 'review-lock-submit');

        $this->withToken($ownerToken)->withHeaders(['Idempotency-Key' => 'review-lock-owner-start'])
            ->postJson('/api/v1/admin/task-submissions/'.$submission->public_id.'/start-review')
            ->assertOk()->assertJsonPath('data.reviewer.id', $owner->public_id);

        $this->withToken($otherToken)->withHeaders(['Idempotency-Key' => 'review-lock-other-start'])
            ->postJson('/api/v1/admin/task-submissions/'.$submission->public_id.'/start-review')
            ->assertUnprocessable();

        $this->withToken($ownerToken)->withHeaders(['Idempotency-Key' => 'review-lock-transfer'])
            ->postJson('/api/v1/admin/task-submissions/'.$submission->public_id.'/transfer-review', [
                'target_admin_id' => $other->public_id, 'reason' => '交由当班审核员继续处理。',
            ])->assertOk()->assertJsonPath('data.reviewer.id', $other->public_id);

        $this->withToken($otherToken)->withHeaders(['Idempotency-Key' => 'review-lock-release'])
            ->postJson('/api/v1/admin/task-submissions/'.$submission->public_id.'/release-review', ['reason' => '暂时释放到公共审核队列。'])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.reviewer', null);
    }

    public function test_step_review_rejects_only_failed_steps_and_resubmission_reuses_approved_material(): void
    {
        $steps = [
            ['id' => 'answer', 'kind' => 'form_input', 'title' => '填写订单号', 'description' => '填写订单号。', 'input_type' => 'text', 'required' => true],
            ['id' => 'proof', 'kind' => 'verification_image', 'title' => '上传订单截图', 'description' => '上传截图。', 'input_type' => 'image', 'required' => true, 'min_files' => 1, 'max_files' => 2],
            ['id' => 'confirm', 'kind' => 'confirmation', 'title' => '确认真实', 'description' => '确认材料真实。', 'input_type' => 'none', 'required' => true],
        ];
        [$token, , $task] = $this->publishedTask('step-partial-revision', [['type' => 'points', 'points' => 10]], 'claim_and_submit', $steps);
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'step-partial-claim');
        $oldProof = $this->cleanFile($user, 'step-partial-old-proof');
        $first = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '首次提交', 'fields' => [
            'steps' => ['answer' => 'ORDER-001'], 'step_files' => ['proof' => [$oldProof->public_id]], 'completed_steps' => ['confirm' => true],
        ]], [$oldProof->public_id], 'step-partial-first');

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'step-partial-start'])
            ->postJson('/api/v1/admin/task-submissions/'.$first->public_id.'/start-review')->assertOk();
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'step-partial-reject'])
            ->postJson('/api/v1/admin/task-submissions/'.$first->public_id.'/review', [
                'decision' => 'reject', 'reason_code' => 'evidence_unclear', 'note' => '订单截图不清晰，请补交。',
                'step_results' => [
                    ['step_id' => 'answer', 'decision' => 'approve'],
                    ['step_id' => 'proof', 'decision' => 'reject', 'reason_code' => 'evidence_unclear', 'note' => '截图不清晰。'],
                    ['step_id' => 'confirm', 'decision' => 'approve'],
                ],
            ])->assertOk()->assertJsonPath('data.revision_step_ids.0', 'proof')
            ->assertJsonFragment(['step_id' => 'proof', 'decision' => 'reject', 'reason_code' => 'evidence_unclear']);

        $presentedClaim = app(TaskClaimService::class)->presentClaim($claim->fresh());
        $this->assertSame('resubmit_failed_steps', $presentedClaim['workflow']['primary_action']['code']);
        $this->assertSame('补交未通过步骤', $presentedClaim['workflow']['primary_action']['label']);
        $this->assertSame(['proof'], $presentedClaim['workflow']['editable_step_ids']);
        $this->assertSame(['answer', 'confirm'], $presentedClaim['workflow']['locked_step_ids']);
        $this->assertTrue($presentedClaim['workflow']['revision_only']);
        $this->assertSame('proof', $presentedClaim['last_review']['step_results'][1]['step_id']);

        $newProof = $this->cleanFile($user, 'step-partial-new-proof');
        $second = app(TaskSubmissionService::class)->submit($user, $claim->fresh(), ['text' => '只补交失败截图', 'fields' => [
            'step_files' => ['proof' => [$newProof->public_id]],
        ]], [$newProof->public_id], 'step-partial-second');

        $this->assertSame($first->id, $second->supersedes_submission_id);
        $this->assertSame(['proof'], $second->revision_step_ids);
        $this->assertSame('ORDER-001', $second->content_snapshot['fields']['steps']['answer']);
        $this->assertTrue($second->content_snapshot['fields']['completed_steps']['confirm']);
        $this->assertSame([$newProof->public_id], $second->content_snapshot['fields']['step_files']['proof']);
        $this->assertSame([$newProof->public_id], $second->file_public_ids);
        $this->assertDatabaseCount('task_step_reviews', 3);

        $this->withToken($token)->getJson('/api/v1/admin/task-review-reasons')
            ->assertOk()->assertJsonFragment(['code' => 'evidence_unclear', 'label' => '证明材料不清晰']);
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
        $this->assertSame('completed', $claim->fresh()->execution_status);
        $this->assertSame('approved', $claim->fresh()->review_status);
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
        $this->assertSame('revision_required', $claim->fresh()->execution_status);
        $this->assertSame('needs_revision', $claim->fresh()->review_status);
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
            'minimum_points' => 0, 'valid_days' => 30, 'total_quantity' => 10, 'status' => 'active', 'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', 'coupon-retry-template'),
        ]);
        $pool = $this->pool($token, 'coupon-retry-pool', 'coupon', 10, $template->public_id);
        $task = $this->createAndPublish($token, 'coupon-retry', [['type' => 'coupon', 'coupon_template_id' => $template->public_id, 'quantity' => 2]], [$pool['id']], 'claim_and_submit');
        $this->assertSame(10, TaskRewardBudget::query()->where('task_id', $task->id)->value('locked_amount'));
        $user = $this->eligibleUser();
        $claim = app(TaskClaimService::class)->claim($user, $task, 'coupon-retry-claim');
        $submission = app(TaskSubmissionService::class)->submit($user, $claim, ['text' => '领取优惠券', 'fields' => []], [], 'coupon-retry-submit');

        $template->update(['status' => 'inactive']);
        app(TaskSubmissionService::class)->review($admin, $submission, 'approve', null, '审核通过', 'coupon-retry-review');
        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSame('delivery_failed', $claim->fresh()->reward_status);
        $this->assertDatabaseHas('task_reward_deliveries', ['status' => 'failed']);

        $delivery = TaskRewardDelivery::query()->firstOrFail();
        $this->withToken($token)->getJson('/api/v1/admin/task-reward-deliveries?status=failed')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $delivery->public_id)
            ->assertJsonPath('data.items.0.status', 'failed')
            ->assertJsonPath('data.items.0.attempt_count', 1)
            ->assertJsonPath('data.pagination.total', 1);

        $template->update(['status' => 'active']);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'coupon-retry-manual-admin'])
            ->postJson('/api/v1/admin/task-reward-deliveries/'.$delivery->public_id.'/retry', ['reason' => '优惠券模板已重新启用，执行人工补发。'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'delivered');
        $this->assertSame('delivered', $claim->fresh()->reward_status);
        $this->assertDatabaseCount('user_coupons', 2);
        $this->assertDatabaseHas('task_reward_deliveries', ['status' => 'delivered', 'amount' => 2]);
        $this->assertDatabaseHas('task_reward_budgets', ['task_id' => $task->id, 'issued_amount' => 2, 'locked_amount' => 10]);
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
