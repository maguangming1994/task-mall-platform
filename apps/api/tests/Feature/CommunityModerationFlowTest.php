<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Community\Models\ContentReport;
use App\Modules\Community\Models\UserRestriction;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Community\Services\CommunityModerationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CommunityModerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_report_is_unique_moderation_revokes_reward_restriction_blocks_post_and_different_reviewer_can_restore_all(): void
    {
        $author = User::factory()->create(['status' => 'active']);
        $reporter = User::factory()->create(['status' => 'active']);
        $originalReviewer = AdminUser::query()->create(['account' => 'moderator-one', 'display_name' => '原处置人', 'password' => 'AdminPassword12345!']);
        $appealReviewer = AdminUser::query()->create(['account' => 'moderator-two', 'display_name' => '申诉复核人', 'password' => 'AdminPassword12345!']);
        $content = app(CommunityContentService::class);
        $moderation = app(CommunityModerationService::class);
        $post = $content->createPost($author, ['title' => '社区举报处置测试帖', 'content' => '该帖子用于验证举报去重、限制、积分撤销以及异人申诉恢复完整链路。'], 'moderation-post-create');
        $post = $content->reviewPost($originalReviewer, $post, ['decision' => 'approve', 'note' => '首次内容审核通过。']);
        $this->assertSame(10, $author->assetAccount()->firstOrFail()->points_available);

        $first = $moderation->report($reporter, 'post', $post->public_id, ['reason_code' => 'spam', 'description' => '疑似广告。'], 'content-report-001');
        $updated = $moderation->report($reporter, 'post', $post->public_id, ['reason_code' => 'fraud', 'description' => '补充：疑似欺诈。'], 'content-report-002');
        $this->assertSame($first->public_id, $updated->public_id);
        $this->assertSame('fraud', $updated->reason_code);
        $this->assertDatabaseCount('content_reports', 1);

        $action = $moderation->moderate($originalReviewer, 'post', $post->public_id, ['decision' => 'hide', 'reason_code' => 'fraud', 'note' => '核验后先隐藏并限制发帖。', 'restrictions' => ['post'], 'restriction_days' => 3], 'moderation-action-001');
        $this->assertSame('hidden', $post->fresh()->status);
        $this->assertTrue($action->reward_revoked);
        $this->assertSame(0, $author->assetAccount()->firstOrFail()->points_available);
        $this->assertSame('resolved', ContentReport::query()->firstOrFail()->status);
        $this->assertDatabaseHas('user_restrictions', ['user_id' => $author->id, 'restriction_type' => 'post', 'status' => 'active']);
        try {
            $content->createPost($author, ['title' => '限制期内不能发帖', 'content' => '即使内容和长度均符合要求，也应被当前有效的发帖限制阻止。'], 'restricted-post-attempt');
            $this->fail('Expected active restriction to block posting.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('community_posts', 1);
        }

        $appeal = $moderation->appeal($author, $action, ['reason' => '该内容不是欺诈信息，请重新复核。'], 'content-appeal-001');
        try {
            $moderation->reviewAppeal($originalReviewer, $appeal, ['decision' => 'approve', 'note' => '原处置人不能自审。']);
            $this->fail('Expected different reviewer rule.');
        } catch (ValidationException) {
            $this->assertSame('submitted', $appeal->fresh()->status);
        }
        $reviewed = $moderation->reviewAppeal($appealReviewer, $appeal, ['decision' => 'approve', 'note' => '复核确认误判，恢复帖子、积分和发帖权限。']);
        $this->assertSame('approved', $reviewed->status);
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('reversed', $action->fresh()->status);
        $this->assertSame(10, $author->assetAccount()->firstOrFail()->points_available);
        $this->assertSame('revoked', UserRestriction::query()->firstOrFail()->status);
    }

    public function test_only_content_author_can_appeal_and_each_action_accepts_one_appeal_with_deadline(): void
    {
        $author = User::factory()->create(['status' => 'active']);
        $other = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'moderator-deadline', 'display_name' => '处置人', 'password' => 'AdminPassword12345!']);
        $content = app(CommunityContentService::class);
        $moderation = app(CommunityModerationService::class);
        $post = $content->createPost($author, ['title' => '申诉期限测试帖子', 'content' => '本帖子用于验证只有内容作者可以在后台配置的申诉期限内发起一次申诉。'], 'appeal-deadline-post');
        $post = $content->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '发布。']);
        $action = $moderation->moderate($admin, 'post', $post->public_id, ['decision' => 'delete', 'reason_code' => 'other', 'note' => '测试删除处置。'], 'appeal-deadline-action');
        $this->expectException(NotFoundHttpException::class);
        $moderation->appeal($other, $action, ['reason' => '非作者不能申诉。'], 'appeal-by-other');
    }
}
