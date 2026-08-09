<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Services\CommunityContentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommunityContentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_post_is_published_only_after_review_rewarded_once_and_edit_keeps_last_approved_version_visible(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'community-reviewer', 'display_name' => '内容审核员', 'password' => 'AdminPassword12345!']);
        $service = app(CommunityContentService::class);
        $post = $service->createPost($user, ['title' => '这是首个社区帖子', 'content' => '这是一段超过二十个字的社区帖子正文，用于验证先审后发流程。'], 'community-post-create-001');
        $same = $service->createPost($user, ['title' => '重复请求不覆盖', 'content' => '重复请求正文不会覆盖首次提交的不可变版本内容。'], 'community-post-create-001');
        $this->assertSame($post->public_id, $same->public_id);
        $this->assertSame('pending_review', $post->status);
        $this->getJson('/api/v1/community/posts')->assertOk()->assertJsonCount(0, 'data.items');

        $published = $service->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '内容合规。']);
        $this->assertSame('published', $published->status);
        $this->assertSame(10, $user->assetAccount()->firstOrFail()->points_available);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->getJson('/api/v1/community/posts')->assertOk()->assertJsonPath('data.items.0.title', '这是首个社区帖子');

        $edited = $service->editPost($user, $published, ['title' => '这是修改后的帖子标题', 'content' => '修改后的正文同样超过二十个字，并且审核通过前不替换线上旧版本。']);
        $this->assertSame(2, $edited->current_version_no);
        $this->assertSame(1, $edited->published_version_no);
        $this->getJson('/api/v1/community/posts')->assertJsonPath('data.items.0.title', '这是首个社区帖子');
        $service->reviewPost($admin, $edited, ['decision' => 'approve', 'note' => '修改版本审核通过。']);
        $this->getJson('/api/v1/community/posts')->assertJsonPath('data.items.0.title', '这是修改后的帖子标题');
        $this->assertSame(10, $user->assetAccount()->firstOrFail()->points_available);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('community_post_versions', 2);
    }

    public function test_comment_sensitive_filter_two_level_limit_and_daily_reward_cap_are_enforced(): void
    {
        $author = User::factory()->create(['status' => 'active']);
        $commenter = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'comment-reviewer', 'display_name' => '评论审核员', 'password' => 'AdminPassword12345!']);
        $service = app(CommunityContentService::class);
        $post = $service->createPost($author, ['title' => '评论规则测试帖子', 'content' => '这是一段用于评论规则专项测试的帖子正文，长度满足后台默认要求。'], 'comment-post-create-001');
        $post = $service->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '帖子通过。']);

        $first = $service->createComment($commenter, $post, ['content' => '这是一条内容完整并符合奖励要求的评论。'], 'community-comment-001');
        $this->assertSame('published', $first->status);
        $this->assertTrue($first->reward_granted);
        $reply = $service->createComment($commenter, $post, ['content' => '这是对一级评论的正常回复内容。', 'parent_comment_id' => $first->public_id], 'community-comment-002');
        $this->assertSame('published', $reply->status);
        $last = null;
        for ($index = 3; $index <= 11; $index++) {
            $last = $service->createComment($commenter, $post, ['content' => '这是第'.$index.'条满足积分奖励长度要求的有效评论。'], 'community-comment-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
        $this->assertNotNull($last);
        $this->assertFalse($last->reward_granted);
        $this->assertSame(20, $commenter->assetAccount()->firstOrFail()->points_available);
        $this->assertSame(10, CommunityComment::query()->where('user_id', $commenter->id)->where('reward_granted', true)->count());
        $this->expectException(ValidationException::class);
        $service->createComment($commenter, $post, ['content' => '不能继续创建第三级嵌套评论。', 'parent_comment_id' => $reply->public_id], 'community-comment-level-3');
    }

    public function test_sensitive_comment_waits_for_review_and_deleted_rewarded_post_creates_points_debt_when_spent(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'community-debt-reviewer', 'display_name' => '内容审核员', 'password' => 'AdminPassword12345!']);
        $service = app(CommunityContentService::class);
        $post = $service->createPost($user, ['title' => '积分撤销测试帖子', 'content' => '帖子审核通过获得积分，积分消费后删除帖子应形成可追踪欠额。'], 'community-debt-post');
        $post = $service->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '帖子通过。']);
        $comment = $service->createComment($user, $post, ['content' => '请加微信联系我完成代刷服务。'], 'community-sensitive-comment');
        $this->assertSame('pending_review', $comment->status);
        $this->assertSame(10, $user->assetAccount()->firstOrFail()->points_available);
        app(AssetLedgerService::class)->debitPoints($user, 10, '消费已获得的发帖积分。', 'community-spend-post-points');
        $service->deletePost($user, $post);
        $account = $user->assetAccount()->firstOrFail();
        $this->assertSame(0, $account->points_available);
        $this->assertSame(10, $account->points_debt);
        $this->assertDatabaseHas('point_debts', ['user_id' => $user->id, 'source_code' => 'post', 'remaining_debt_points' => 10, 'status' => 'outstanding']);
        $this->assertSoftDeleted('community_posts', ['id' => $post->id]);
    }
}
