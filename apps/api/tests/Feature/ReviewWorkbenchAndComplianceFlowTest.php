<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ContentReport;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Identity\Services\UserIdentityVerificationService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Models\TaskVersion;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 移动审核工作台与合规审核增强（Epic A）验收测试：
 * 闲置审核留痕/终态保护、工作台汇总按权限裁剪、社区审核终态保护与审计、实名人工审核。
 */
class ReviewWorkbenchAndComplianceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_second_hand_approval_records_reviewer_audit_and_blocks_duplicate_review(): void
    {
        [, $sellerToken] = $this->loginAndConsent('13800138201');
        [, $adminToken] = $this->loginAdmin('rw-sh-admin', 'super_admin');

        $itemId = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '待审核的 Kindle 电纸书',
            'description' => '自用无划痕，附带原装保护套。',
            'price' => 299,
            'condition' => 'like_new',
        ])->assertCreated()->json('data.id');

        // 首次通过：返回审核留痕，管理员视图带卖家资料
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$itemId.'/review', [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.audit_status', 'approved')
            ->assertJsonPath('data.reviewed_by_name', 'rw-sh-admin')
            ->assertJsonPath('data.seller.identity_verified', false);
        $this->assertNotNull(DB::table('second_hand_items')->where('public_id', $itemId)->value('reviewed_at'));
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'second_hand.approve',
            'subject_type' => 'second_hand_item',
            'subject_id' => $itemId,
        ]);

        // 重复审核返回 409
        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$itemId.'/review', [
            'decision' => 'reject',
            'reason' => '重复操作',
        ])->assertStatus(409)->assertJsonPath('code', 'REVIEW_ALREADY_FINISHED');
    }

    public function test_second_hand_reject_records_reason_and_audit(): void
    {
        [, $sellerToken] = $this->loginAndConsent('13800138202');
        [, $adminToken] = $this->loginAdmin('rw-sh-rejecter', 'super_admin');

        $itemId = $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '可疑商品',
            'price' => 50,
        ])->assertCreated()->json('data.id');

        $this->withToken($adminToken)->postJson('/api/v1/admin/second-hand/items/'.$itemId.'/review', [
            'decision' => 'reject',
            'reason' => '图片与实物不符',
        ])->assertOk()
            ->assertJsonPath('data.audit_status', 'rejected')
            ->assertJsonPath('data.rejected_reason', '图片与实物不符');
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'second_hand.reject',
            'subject_id' => $itemId,
            'reason' => '图片与实物不符',
        ]);
    }

    public function test_review_workbench_summary_is_scoped_by_permissions(): void
    {
        // 社区侧待办：1 条闲置 + 1 篇待审帖 + 1 条举报
        [, $sellerToken] = $this->loginAndConsent('13800138203');
        $this->withToken($sellerToken)->postJson('/api/v1/second-hand/items', [
            'title' => '工作台计数商品',
            'price' => 12,
        ])->assertCreated();

        $author = User::factory()->create(['status' => 'active']);
        app(CommunityContentService::class)->createPost(
            $author,
            ['title' => '工作台待审帖子', 'content' => '这是一段用于移动工作台计数的社区帖子正文。'],
            'workbench-post-001',
        );
        $reporter = User::factory()->create(['status' => 'active']);
        $otherPost = CommunityPost::query()->create([
            'user_id' => $author->id,
            'title' => '已发布帖子',
            'content' => '用于挂载举报评论的已发布帖子。',
            'category_code' => 'experience',
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', 'workbench-published-post'),
            'published_at' => now(),
        ]);
        $reportedComment = CommunityComment::query()->create([
            'community_post_id' => $otherPost->id,
            'user_id' => $author->id,
            'content' => '被举报评论',
            'status' => 'published',
            'rule_snapshot' => [],
            'idempotency_key_hash' => hash('sha256', 'workbench-reported-comment'),
        ]);
        ContentReport::query()->create([
            'reporter_user_id' => $reporter->id,
            'content_type' => 'comment',
            'content_id' => $reportedComment->id,
            'reason_code' => 'spam',
            'status' => 'submitted',
            'idempotency_key_hash' => hash('sha256', 'workbench-report'),
        ]);

        [, $communityAdminToken] = $this->loginAdmin('rw-community-reviewer', 'community_reviewer');
        $this->withToken($communityAdminToken)->getJson('/api/v1/admin/review-workbench/summary')
            ->assertOk()
            ->assertJsonPath('data.second_hand_pending', 1)
            ->assertJsonPath('data.community_posts_pending', 1)
            ->assertJsonPath('data.community_reports_pending', 1)
            ->assertJsonPath('data.permissions.community', true)
            ->assertJsonPath('data.permissions.task', false)
            ->assertJsonMissingPath('data.task_submissions_pending');

        // 任务侧待办：1 条 submitted
        $this->createTaskSubmission();
        [, $taskAdminToken] = $this->loginAdmin('rw-task-reviewer', 'task_reviewer');
        $this->withToken($taskAdminToken)->getJson('/api/v1/admin/review-workbench/summary')
            ->assertOk()
            ->assertJsonPath('data.task_submissions_pending', 1)
            ->assertJsonPath('data.permissions.task', true)
            ->assertJsonMissingPath('data.second_hand_pending');
    }

    public function test_community_post_and_comment_duplicate_review_returns_409_with_audit(): void
    {
        [, $adminToken] = $this->loginAdmin('rw-community-admin', 'community_reviewer');
        $author = User::factory()->create(['status' => 'active']);
        $post = app(CommunityContentService::class)->createPost(
            $author,
            ['title' => '终态保护帖子', 'content' => '这是一段用于验证重复审核返回 409 的社区帖子正文内容。'],
            'community-409-post',
        );

        $this->withToken($adminToken)->postJson('/api/v1/admin/community/posts/'.$post->public_id.'/review', [
            'decision' => 'approve',
            'note' => '内容合规，通过。',
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.post.approve', 'subject_id' => $post->public_id]);

        $this->withToken($adminToken)->postJson('/api/v1/admin/community/posts/'.$post->public_id.'/review', [
            'decision' => 'reject',
            'reason_code' => 'other',
            'note' => '重复审核尝试。',
        ])->assertStatus(409)->assertJsonPath('code', 'REVIEW_ALREADY_FINISHED');

        // 评论同样有终态保护
        $comment = CommunityComment::query()->create([
            'community_post_id' => $post->id,
            'user_id' => $author->id,
            'content' => '待审核评论',
            'status' => 'pending_review',
            'rule_snapshot' => [],
            'idempotency_key_hash' => hash('sha256', 'community-409-comment'),
        ]);
        $this->withToken($adminToken)->postJson('/api/v1/admin/community/comments/'.$comment->public_id.'/review', [
            'decision' => 'approve',
            'note' => '评论合规。',
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->withToken($adminToken)->postJson('/api/v1/admin/community/comments/'.$comment->public_id.'/review', [
            'decision' => 'reject',
            'reason_code' => 'spam',
            'note' => '重复审核尝试。',
        ])->assertStatus(409)->assertJsonPath('code', 'REVIEW_ALREADY_FINISHED');
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.comment.approve', 'subject_id' => $comment->public_id]);
    }

    public function test_manual_identity_review_approve_reject_conflict_and_permission(): void
    {
        // 超管开启人工审核模式
        [, $superToken] = $this->loginAdmin('rw-identity-super', 'super_admin');
        $this->withToken($superToken)->putJson('/api/v1/admin/identity-verification/settings', [
            'driver' => 'manual',
            'collection_enabled' => true,
            'manual_review_enabled' => true,
            'aliyun' => ['region' => 'cn-hangzhou', 'endpoint' => 'cloudauth.aliyuncs.com', 'timeout_seconds' => 8],
            'reason' => '测试开启人工实名认证。',
        ])->assertOk()->assertJsonPath('data.driver', 'manual');

        // 通过服务层直接产生两笔人工待审实名（用户提交接口不是本用例重点，且路由有每分钟限流）
        $identityService = app(UserIdentityVerificationService::class);
        $approveUser = User::factory()->create(['status' => 'active']);
        $identityService->verify($approveUser, '张三丰', '11010519491231002X');
        $this->assertSame('pending', UserIdentityVerification::query()->where('user_id', $approveUser->id)->value('status'));

        $rejectUser = User::factory()->create(['status' => 'active']);
        $identityService->verify($rejectUser, '李四光', '11010519491231002X');
        $this->assertSame('pending', UserIdentityVerification::query()->where('user_id', $rejectUser->id)->value('status'));

        // 客服（identity.review）看待审列表：仅脱敏信息
        [, $serviceToken] = $this->loginAdmin('rw-identity-service', 'customer_service');
        $list = $this->withToken($serviceToken)->getJson('/api/v1/admin/identity-verifications')
            ->assertOk()->assertJsonCount(2, 'data.items');
        $this->assertStringNotContainsString('identity_number_hash', $list->getContent());

        $pending = UserIdentityVerification::query()->where('user_id', $approveUser->id)
            ->where('status', 'pending')->latest('id')->firstOrFail();

        // 通过
        $this->withToken($serviceToken)->postJson('/api/v1/admin/identity-verifications/'.$pending->public_id.'/review', [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.reviewed_by_name', 'rw-identity-service');
        $this->assertDatabaseHas('user_identity_verifications', [
            'id' => $pending->id,
            'status' => 'verified',
            'failure_code' => null,
        ]);
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'identity.manual_review',
            'subject_id' => $pending->public_id,
        ]);

        // 重复审核 409
        $this->withToken($serviceToken)->postJson('/api/v1/admin/identity-verifications/'.$pending->public_id.'/review', [
            'decision' => 'reject',
        ])->assertStatus(409)->assertJsonPath('code', 'REVIEW_ALREADY_FINISHED');

        // 驳回：状态 failed + manual_rejected
        $rejectPending = UserIdentityVerification::query()->where('user_id', $rejectUser->id)
            ->where('status', 'pending')->latest('id')->firstOrFail();
        $this->withToken($serviceToken)->postJson('/api/v1/admin/identity-verifications/'.$rejectPending->public_id.'/review', [
            'decision' => 'reject',
            'note' => '证件信息与本人不一致',
        ])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseHas('user_identity_verifications', [
            'id' => $rejectPending->id,
            'status' => 'failed',
            'failure_code' => 'manual_rejected',
        ]);

        // 无 identity.review 权限的角色 403
        [, $taskOnlyToken] = $this->loginAdmin('rw-identity-task-only', 'task_reviewer');
        $this->withToken($taskOnlyToken)->getJson('/api/v1/admin/identity-verifications')->assertForbidden();
    }

    private function createTaskSubmission(): TaskSubmission
    {
        $admin = AdminUser::query()->where('account', 'rw-identity-super')->first()
            ?? AdminUser::query()->create(['account' => 'rw-task-root', 'display_name' => 'root', 'password' => 'AdminPassword12345!']);
        $campus = Campus::query()->create(['code' => 'rw-campus', 'name' => '工作台测试校区', 'status' => 'active']);
        $user = User::factory()->create(['campus_id' => $campus->id, 'status' => 'active']);
        $task = Task::query()->create([
            'campus_id' => $campus->id,
            'created_by_admin_id' => $admin->id,
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', 'rw-workbench-task'),
            'published_at' => now(),
        ]);
        $version = TaskVersion::query()->create([
            'task_id' => $task->id,
            'version_no' => 1,
            'status' => 'published',
            'changed_fields' => [],
            'content' => ['title' => '工作台任务', 'steps' => []],
            'created_by_admin_id' => $admin->id,
            'published_at' => now(),
        ]);
        $claim = TaskClaim::query()->create([
            'task_id' => $task->id,
            'task_version_id' => $version->id,
            'user_id' => $user->id,
            'enrollment_source' => 'public',
            'cycle_key' => 'once',
            'task_snapshot' => ['title' => '工作台任务', 'steps' => []],
            'status' => 'submitted',
            'execution_status' => 'under_review',
            'review_status' => 'queued',
            'completion_deadline_at' => now()->addDay(),
            'claim_idempotency_key_hash' => hash('sha256', 'rw-workbench-claim'),
        ]);

        return TaskSubmission::query()->create([
            'task_claim_id' => $claim->id,
            'user_id' => $user->id,
            'submission_no' => 1,
            'status' => 'submitted',
            'content_snapshot' => ['text' => '工作台提交'],
            'file_public_ids' => [],
            'idempotency_key_hash' => hash('sha256', 'rw-workbench-submission'),
            'submitted_at' => now(),
            'review_due_at' => now()->addHour(),
        ]);
    }

    /** @return array{User, string} */
    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'rw-test-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'rw-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])
                ->postJson('/api/v1/me/legal-consents', [
                    'legal_document_version_id' => $document['id'],
                    'action' => 'accept',
                    'acknowledge_service_restriction' => false,
                ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }

    /** @return array{AdminUser, string} */
    private function loginAdmin(string $account, string $role): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
