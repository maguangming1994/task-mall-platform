<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ContentAppeal;
use App\Modules\Community\Models\ContentReport;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskAppeal;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskRewardDelivery;
use App\Modules\Task\Models\TaskRewardReservation;
use App\Modules\Task\Models\TaskRiskCase;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Models\TaskVersion;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusAgentIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campus_agent_cannot_view_or_operate_another_campus_task_workflow(): void
    {
        $this->seed(RbacSeeder::class);
        $root = $this->createAdmin('isolation-task-root', 'super_admin');
        $agent = $this->createAdmin('isolation-task-agent', 'campus_agent');
        $assigned = Campus::query()->create(['code' => 'task-assigned', 'name' => '任务本校', 'status' => 'active']);
        $outside = Campus::query()->create(['code' => 'task-outside', 'name' => '任务外校', 'status' => 'active']);
        $agent->campuses()->attach($assigned->id, ['is_primary' => true]);
        $own = $this->taskWorkflow($assigned, $root, 'own');
        $foreign = $this->taskWorkflow($outside, $root, 'foreign');
        $token = $this->login($agent);

        $this->withToken($token)->getJson('/api/v1/admin/task-submissions')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['submission']->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/task-reward-deliveries')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['delivery']->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/task-appeals')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['appeal']->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/task-risk-cases?status=open')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['risk']->public_id);

        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-submission-review')
            ->postJson("/api/v1/admin/task-submissions/{$foreign['submission']->public_id}/start-review")
            ->assertNotFound();
        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-reward-retry')
            ->postJson("/api/v1/admin/task-reward-deliveries/{$foreign['delivery']->public_id}/retry", ['reason' => '跨校重试不应允许'])
            ->assertNotFound();
        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-appeal-review')
            ->postJson("/api/v1/admin/task-appeals/{$foreign['appeal']->public_id}/review", ['decision' => 'reject', 'reason' => '跨校申诉不应允许'])
            ->assertNotFound();
        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-risk-review')
            ->postJson("/api/v1/admin/task-risk-cases/{$foreign['risk']->public_id}/review", ['decision' => 'clear', 'actions' => [], 'reason' => '跨校风控不应允许'])
            ->assertNotFound();
    }

    public function test_campus_agent_cannot_view_or_moderate_another_campus_community_workflow(): void
    {
        $this->seed(RbacSeeder::class);
        $root = $this->createAdmin('isolation-community-root', 'super_admin');
        $agent = $this->createAdmin('isolation-community-agent', 'campus_agent');
        $assigned = Campus::query()->create(['code' => 'community-assigned', 'name' => '社区本校', 'status' => 'active']);
        $outside = Campus::query()->create(['code' => 'community-outside', 'name' => '社区外校', 'status' => 'active']);
        $agent->campuses()->attach($assigned->id, ['is_primary' => true]);
        $own = $this->communityWorkflow($assigned, $root, 'own');
        $foreign = $this->communityWorkflow($outside, $root, 'foreign');
        $token = $this->login($agent);

        $this->withToken($token)->getJson('/api/v1/admin/community/comments')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['comment']->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/community/reports')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['report']->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/community/appeals')
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own['appeal']->public_id);

        $this->withToken($token)->postJson("/api/v1/admin/community/comments/{$foreign['comment']->public_id}/review", [
            'decision' => 'reject', 'reason_code' => 'spam', 'note' => '跨校评论不应允许',
        ])->assertNotFound();
        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-community-moderate')
            ->postJson("/api/v1/admin/community/comment/{$foreign['comment']->public_id}/moderate", [
                'decision' => 'hide', 'reason_code' => 'spam', 'note' => '跨校举报处置不应允许',
            ])->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/admin/community/appeals/{$foreign['appeal']->public_id}/review", [
            'decision' => 'reject', 'note' => '跨校申诉不应允许',
        ])->assertNotFound();
    }

    /** @return array{submission: TaskSubmission, delivery: TaskRewardDelivery, appeal: TaskAppeal, risk: TaskRiskCase} */
    private function taskWorkflow(Campus $campus, AdminUser $admin, string $key): array
    {
        $user = User::factory()->create(['campus_id' => $campus->id]);
        $task = Task::query()->create([
            'campus_id' => $campus->id,
            'created_by_admin_id' => $admin->id,
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', "task-{$key}"),
            'published_at' => now(),
        ]);
        $version = TaskVersion::query()->create([
            'task_id' => $task->id,
            'version_no' => 1,
            'status' => 'published',
            'changed_fields' => [],
            'content' => ['title' => "任务 {$key}", 'steps' => []],
            'created_by_admin_id' => $admin->id,
            'published_at' => now(),
        ]);
        $claim = TaskClaim::query()->create([
            'task_id' => $task->id,
            'task_version_id' => $version->id,
            'user_id' => $user->id,
            'enrollment_source' => 'public',
            'cycle_key' => 'once',
            'task_snapshot' => ['title' => "任务 {$key}", 'steps' => []],
            'status' => 'submitted',
            'execution_status' => 'under_review',
            'review_status' => 'queued',
            'completion_deadline_at' => now()->addDay(),
            'claim_idempotency_key_hash' => hash('sha256', "claim-{$key}"),
        ]);
        $submission = TaskSubmission::query()->create([
            'task_claim_id' => $claim->id,
            'user_id' => $user->id,
            'submission_no' => 1,
            'status' => 'submitted',
            'content_snapshot' => ['text' => "提交 {$key}"],
            'file_public_ids' => [],
            'idempotency_key_hash' => hash('sha256', "submission-{$key}"),
            'submitted_at' => now(),
            'review_due_at' => now()->addHour(),
        ]);
        $appeal = TaskAppeal::query()->create([
            'task_claim_id' => $claim->id,
            'task_submission_id' => $submission->id,
            'appeal_no' => 1,
            'user_id' => $user->id,
            'status' => 'submitted',
            'reason' => "申诉 {$key}",
            'original_reviewer_admin_id' => $admin->id,
            'create_idempotency_key_hash' => hash('sha256', "appeal-{$key}"),
            'appeal_deadline_at' => now()->addDay(),
        ]);
        $risk = TaskRiskCase::query()->create([
            'task_id' => $task->id,
            'task_claim_id' => $claim->id,
            'source' => 'submission_rule',
            'risk_level' => 'medium',
            'status' => 'open',
            'response_due_at' => now()->addHour(),
        ]);
        $pool = RewardBudgetPool::query()->create([
            'name' => "预算 {$key}",
            'asset_type' => 'points',
            'total_amount' => 100,
            'status' => 'active',
            'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', "pool-{$key}"),
        ]);
        $budget = TaskRewardBudget::query()->create([
            'task_id' => $task->id,
            'task_version_id' => $version->id,
            'reward_budget_pool_id' => $pool->id,
            'reward_sequence' => 1,
            'asset_type' => 'points',
            'unit_amount' => 10,
            'max_deliveries_total' => 10,
            'locked_amount' => 100,
            'status' => 'locked',
        ]);
        $reservation = TaskRewardReservation::query()->create([
            'task_claim_id' => $claim->id,
            'task_reward_budget_id' => $budget->id,
            'reward_sequence' => 1,
            'asset_type' => 'points',
            'amount' => 10,
            'status' => 'settled',
            'idempotency_key_hash' => hash('sha256', "reservation-{$key}"),
            'reserved_at' => now(),
            'settled_at' => now(),
        ]);
        $delivery = TaskRewardDelivery::query()->create([
            'task_claim_id' => $claim->id,
            'task_reward_reservation_id' => $reservation->id,
            'asset_type' => 'points',
            'amount' => 10,
            'reward_snapshot' => [],
            'status' => 'failed',
        ]);

        return compact('submission', 'delivery', 'appeal', 'risk');
    }

    /** @return array{comment: CommunityComment, report: ContentReport, appeal: ContentAppeal} */
    private function communityWorkflow(Campus $campus, AdminUser $admin, string $key): array
    {
        $author = User::factory()->create(['campus_id' => $campus->id]);
        $reporter = User::factory()->create(['campus_id' => $campus->id]);
        $post = CommunityPost::query()->create([
            'campus_id' => $campus->id,
            'user_id' => $author->id,
            'title' => "帖子 {$key}",
            'content' => "内容 {$key}",
            'category_code' => 'experience',
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', "post-{$key}"),
            'published_at' => now(),
        ]);
        $comment = CommunityComment::query()->create([
            'community_post_id' => $post->id,
            'user_id' => $author->id,
            'content' => "评论 {$key}",
            'status' => 'pending_review',
            'rule_snapshot' => [],
            'idempotency_key_hash' => hash('sha256', "comment-{$key}"),
        ]);
        $report = ContentReport::query()->create([
            'reporter_user_id' => $reporter->id,
            'content_type' => 'comment',
            'content_id' => $comment->id,
            'reason_code' => 'spam',
            'description' => "举报 {$key}",
            'status' => 'submitted',
            'idempotency_key_hash' => hash('sha256', "report-{$key}"),
        ]);
        $action = ModerationAction::query()->create([
            'content_type' => 'comment',
            'content_id' => $comment->id,
            'content_author_user_id' => $author->id,
            'action_type' => 'hide',
            'reason_code' => 'spam',
            'note' => "处置 {$key}",
            'previous_status' => 'published',
            'status' => 'effective',
            'admin_user_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', "action-{$key}"),
            'appeal_deadline_at' => now()->addDay(),
        ]);
        $appeal = ContentAppeal::query()->create([
            'moderation_action_id' => $action->id,
            'user_id' => $author->id,
            'reason' => "申诉 {$key}",
            'status' => 'submitted',
            'idempotency_key_hash' => hash('sha256', "content-appeal-{$key}"),
        ]);

        return compact('comment', 'report', 'appeal');
    }

    private function createAdmin(string $account, string $role): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));

        return $admin;
    }

    private function login(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()->json('data.access_token');
    }
}
