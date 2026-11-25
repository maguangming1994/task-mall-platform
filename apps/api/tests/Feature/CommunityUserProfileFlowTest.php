<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Community\Models\CommunityActivity;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\UserBlock;
use App\Modules\Community\Models\UserFollow;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\SecondHand\Models\SecondHandRating;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityUserProfileFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_public_profile_supports_cross_campus_visits_and_all_safe_content_types(): void
    {
        [$viewerToken, $viewer] = $this->phoneLoginWithConsents('13800139001');
        $viewer->campus->update(['isolation_mode' => 'strict']);
        $otherCampus = $this->campus('profile-other-campus', '另一所大学');
        $target = User::factory()->create([
            'campus_id' => $otherCampus->id,
            'nickname' => '公开主页同学',
            'bio' => '喜欢校园生活，也愿意认识新朋友。',
            'grade_year' => 2024,
            'college' => '信息学院',
            'major' => '软件工程',
            'status' => 'active',
        ]);
        $publicPost = $this->createPost($target, '公开帖子', 'public');
        $this->createPost($target, '关注可见帖子', 'followers');
        $this->createPost($target, '匿名帖子不应关联主页', 'anonymous');
        $this->createPost($target, '未发布帖子', 'public', 'pending_review');
        $item = $this->item($target);
        $activity = $this->activity($target);
        [$task, $version] = $this->publishedTask($target);
        $claim = TaskClaim::query()->create([
            'task_id' => $task->id,
            'task_version_id' => $version->id,
            'user_id' => $target->id,
            'enrollment_source' => 'user',
            'cycle_key' => '2026-11',
            'task_snapshot' => ['title' => '已完成校园任务', 'description_text' => '任务公开说明', 'secret_submission' => '不能公开的提交材料'],
            'status' => 'approved',
            'slot_status' => 'consumed',
            'completion_deadline_at' => now()->addDay(),
            'claim_idempotency_key_hash' => hash('sha256', 'profile-claim'),
            'approved_at' => now(),
        ]);
        $order = SecondHandOrder::query()->create([
            'buyer_user_id' => $viewer->id,
            'seller_user_id' => $target->id,
            'item_id' => $item->id,
            'title_snapshot' => $item->title,
            'price' => $item->price,
            'status' => 'completed',
            'paid_amount' => $item->price,
            'idempotency_key_hash' => hash('sha256', 'profile-rating-order'),
            'completed_at' => now(),
        ]);
        $rating = SecondHandRating::query()->create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'rater_user_id' => $viewer->id,
            'ratee_user_id' => $target->id,
            'score' => 5,
            'content' => '沟通真诚，交易顺利。',
        ]);

        $profileUrl = "/api/v1/community/users/{$target->public_id}/profile";
        $this->withToken($viewerToken)->getJson($profileUrl)
            ->assertOk()
            ->assertJsonPath('data.nickname', '公开主页同学')
            ->assertJsonPath('data.school_name', '另一所大学')
            ->assertJsonPath('data.bio', '喜欢校园生活，也愿意认识新朋友。')
            ->assertJsonPath('data.visitor_count', 1)
            ->assertJsonPath('data.content_counts.posts', 1)
            ->assertJsonPath('data.content_counts.second_hand', 1)
            ->assertJsonPath('data.content_counts.activities', 1)
            ->assertJsonPath('data.content_counts.tasks', 1)
            ->assertJsonPath('data.content_counts.claims', 1)
            ->assertJsonPath('data.content_counts.impressions', 1);
        $this->withToken($viewerToken)->getJson($profileUrl)
            ->assertOk()
            ->assertJsonPath('data.visitor_count', 1)
            ->assertJsonCount(1, 'data.recent_visitors');

        $expected = [
            'posts' => $publicPost->public_id,
            'second_hand' => $item->public_id,
            'activities' => $activity->public_id,
            'tasks' => $task->public_id,
            'claims' => $claim->public_id,
            'impressions' => $rating->public_id,
        ];
        foreach ($expected as $type => $id) {
            $response = $this->withToken($viewerToken)->getJson("/api/v1/community/users/{$target->public_id}/content?type={$type}&page=1&page_size=10")
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 1)
                ->assertJsonPath('data.items.0.id', $id);
            if ($type === 'claims') {
                $response->assertJsonPath('data.items.0.task_id', $task->public_id)
                    ->assertJsonMissingPath('data.items.0.task_snapshot')
                    ->assertJsonMissingPath('data.items.0.secret_submission');
            }
        }

        $this->withToken($viewerToken)->postJson("/api/v1/community/users/{$target->public_id}/follow")->assertOk();
        $this->withToken($viewerToken)->getJson("/api/v1/community/users/{$target->public_id}/content?type=posts")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonMissing(['title' => '匿名帖子不应关联主页']);
    }

    public function test_block_removes_follows_and_enforces_content_chat_and_feed_restrictions(): void
    {
        [$viewerToken, $viewer] = $this->phoneLoginWithConsents('13800139002');
        [$targetToken, $target] = $this->phoneLoginWithConsents('13800139003');
        $viewerPost = $this->createPost($viewer, '拉黑前 viewer 公开帖子');
        $targetPost = $this->createPost($target, '拉黑前 target 公开帖子');
        $targetItem = $this->item($target);
        $targetActivity = $this->activity($target);
        UserFollow::query()->create(['follower_id' => $viewer->id, 'following_id' => $target->id]);
        UserFollow::query()->create(['follower_id' => $target->id, 'following_id' => $viewer->id]);
        $conversationId = $this->withToken($viewerToken)->postJson('/api/v1/chat/conversations', ['user_id' => $target->public_id])
            ->assertOk()->json('data.id');
        $conversation = ChatConversation::query()->where('public_id', $conversationId)->firstOrFail();

        $this->withToken($viewerToken)->postJson("/api/v1/community/users/{$target->public_id}/block")
            ->assertOk()->assertJsonPath('data.is_blocked', true)->assertJsonPath('data.is_followed', false);
        $this->assertDatabaseHas('user_blocks', ['blocker_user_id' => $viewer->id, 'blocked_user_id' => $target->id]);
        $this->assertSame(0, UserFollow::query()->whereIn('follower_id', [$viewer->id, $target->id])->count());

        $this->withToken($viewerToken)->getJson("/api/v1/community/users/{$target->public_id}/profile")
            ->assertOk()->assertJsonPath('data.is_blocked', true)->assertJsonPath('data.content_counts.posts', 0);
        $this->withToken($targetToken)->getJson("/api/v1/community/users/{$viewer->public_id}/profile")->assertNotFound();
        $this->withToken($viewerToken)->postJson("/api/v1/community/users/{$target->public_id}/follow")->assertForbidden();
        $this->withToken($viewerToken)->postJson('/api/v1/chat/conversations', ['user_id' => $target->public_id])->assertForbidden();
        $this->withToken($viewerToken)->postJson("/api/v1/chat/conversations/{$conversation->public_id}/messages", ['content' => '拉黑后不能发送'])->assertForbidden();
        $this->withToken($targetToken)->postJson("/api/v1/chat/conversations/{$conversation->public_id}/messages", ['content' => '对方也不能发送'])->assertForbidden();
        $this->withToken($viewerToken)->getJson('/api/v1/community/posts')->assertOk()->assertJsonMissing(['id' => $targetPost->public_id]);
        $this->withToken($targetToken)->getJson('/api/v1/community/posts')->assertOk()->assertJsonMissing(['id' => $viewerPost->public_id]);
        $this->withToken($viewerToken)->getJson("/api/v1/community/posts/{$targetPost->public_id}")->assertNotFound();
        $this->withToken($viewerToken)->getJson('/api/v1/second-hand/items')->assertOk()->assertJsonMissing(['id' => $targetItem->public_id]);
        $this->withToken($viewerToken)->getJson('/api/v1/community/activities')->assertOk()->assertJsonMissing(['id' => $targetActivity->public_id]);

        $this->withToken($viewerToken)->deleteJson("/api/v1/community/users/{$target->public_id}/block")
            ->assertOk()->assertJsonPath('data.is_blocked', false);
        $this->assertSame(0, UserBlock::query()->count());
        $this->withToken($viewerToken)->getJson('/api/v1/community/posts')->assertOk()->assertJsonFragment(['id' => $targetPost->public_id]);
        $this->withToken($viewerToken)->getJson('/api/v1/second-hand/items')->assertOk()->assertJsonFragment(['id' => $targetItem->public_id]);
        $this->withToken($viewerToken)->getJson('/api/v1/community/activities')->assertOk()->assertJsonFragment(['id' => $targetActivity->public_id]);
    }

    private function createPost(User $user, string $title, string $visibility = 'public', string $status = 'published'): CommunityPost
    {
        return CommunityPost::query()->create([
            'campus_id' => $user->campus_id,
            'user_id' => $user->id,
            'title' => $title,
            'content' => $title.'的完整公开正文内容。',
            'category_code' => 'experience',
            'visibility' => $visibility,
            'status' => $status,
            'published_version_no' => $status === 'published' ? 1 : null,
            'published_at' => $status === 'published' ? now() : null,
            'idempotency_key_hash' => hash('sha256', 'profile-post-'.$user->id.'-'.$title),
        ]);
    }

    private function item(User $user): SecondHandItem
    {
        return SecondHandItem::query()->create([
            'campus_id' => $user->campus_id,
            'seller_user_id' => $user->id,
            'title' => '公开闲置物品',
            'description' => '保存良好的校园闲置。',
            'price' => 1999,
            'condition' => 'like_new',
            'status' => 'on_sale',
            'audit_status' => 'approved',
            'idempotency_key_hash' => hash('sha256', 'profile-item-'.$user->id),
        ]);
    }

    private function activity(User $user): CommunityActivity
    {
        return CommunityActivity::query()->create([
            'campus_id' => $user->campus_id,
            'creator_user_id' => $user->id,
            'category_code' => 'study',
            'title' => '周末一起自习',
            'details' => '图书馆一起学习两小时。',
            'location' => '图书馆二楼',
            'starts_at' => now()->addDays(3),
            'participant_limit' => 6,
            'status' => 'approved',
            'idempotency_key_hash' => hash('sha256', 'profile-activity-'.$user->id),
        ]);
    }

    /** @return array{0:Task,1:TaskVersion} */
    private function publishedTask(User $user): array
    {
        $admin = AdminUser::query()->create([
            'account' => 'profile-publisher-'.$user->id,
            'display_name' => '校园任务发布者',
            'password' => 'AdminPassword12345!',
        ]);
        TaskPublisher::query()->create([
            'publisher_type' => 'user',
            'admin_user_id' => $admin->id,
            'linked_user_id' => $user->id,
            'verification_status' => 'verified',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'campus_id' => $user->campus_id,
            'created_by_admin_id' => $admin->id,
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'published_at' => now(),
            'idempotency_key_hash' => hash('sha256', 'profile-task-'.$user->id),
        ]);
        $version = TaskVersion::query()->create([
            'task_id' => $task->id,
            'version_no' => 1,
            'status' => 'published',
            'change_type' => 'initial',
            'changed_fields' => [],
            'content' => ['title' => '公开校园任务', 'description' => '公开任务说明', 'description_text' => '公开任务说明', 'task_type' => 'one_off', 'reward_budgets' => ['secret' => '不返回']],
            'created_by_admin_id' => $admin->id,
            'published_at' => now(),
        ]);

        return [$task, $version];
    }

    private function campus(string $code, string $name): Campus
    {
        return Campus::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'catalog_type' => 'manual',
            'catalog_status' => 'manual',
            'isolation_mode' => 'legacy',
        ]);
    }

    /** @return array{0:string,1:User} */
    private function phoneLoginWithConsents(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'profile-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $user = User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail();
        $this->bindTestCampus($user);
        foreach (['community', 'account'] as $scope) {
            foreach ($this->withToken($token)->getJson("/api/v1/me/legal-consents/pending?scope={$scope}")->assertOk()->json('data') as $index => $document) {
                $this->withToken($token)->withHeaders(['Idempotency-Key' => "profile-consent-{$phone}-{$scope}-{$index}", 'X-Client-Platform' => 'h5-browser'])
                    ->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])
                    ->assertCreated();
            }
        }

        return [$token, $user];
    }
}
