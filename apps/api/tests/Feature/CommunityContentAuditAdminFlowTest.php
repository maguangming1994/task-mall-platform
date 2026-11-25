<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityContentSecurityLog;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Services\CommunityContentSecurityService;
use App\Modules\Community\Services\CommunityContentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityContentAuditAdminFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.green.access_key_id' => null, 'services.green.access_key_secret' => null]);
    }

    public function test_post_admin_list_uses_current_versions_real_stats_pagination_and_campus_scope(): void
    {
        $ownCampus = $this->campus('audit-list-own', '审核列表本校');
        $foreignCampus = $this->campus('audit-list-foreign', '审核列表外校');
        [$admin, $token] = $this->admin('audit-list-agent', $ownCampus);
        $author = $this->user($ownCampus, '本校帖子作者');
        $foreignAuthor = $this->user($foreignCampus, '外校帖子作者');

        $pending = $this->createPost($author, '本校待审帖子', 'audit-list-pending', 'guide');
        $published = $this->createPost($author, '本校已发布帖子', 'audit-list-published');
        $this->content()->reviewPost($admin, $published, ['decision' => 'approve', 'note' => '发布测试帖子。']);
        $rejected = $this->createPost($author, '本校已驳回帖子', 'audit-list-rejected');
        $this->content()->reviewPost($admin, $rejected, ['decision' => 'reject', 'reason_code' => 'spam', 'note' => '驳回测试帖子。']);
        $edited = $this->createPost($author, '已发布帖子的旧标题', 'audit-list-edited');
        $this->content()->reviewPost($admin, $edited, ['decision' => 'approve', 'note' => '先发布旧版本。']);
        $currentTitle = '当前编辑版本等待复审';
        $edited = $this->content()->editPost($author, $edited, [
            'title' => $currentTitle,
            'content' => '这是当前编辑版本的正文，需要重新等待人工审核且不能替换公开旧版本。',
            'category_code' => 'question',
        ]);
        $hidden = $this->createPost($author, '本校已隐藏帖子', 'audit-list-hidden');
        $this->content()->reviewPost($admin, $hidden, ['decision' => 'approve', 'note' => '先发布再隐藏。']);
        $hidden->update(['status' => 'hidden']);
        $deleted = $this->createPost($author, '本校已删除帖子', 'audit-list-deleted');
        $this->content()->deletePost($author, $deleted);
        $foreign = $this->createPost($foreignAuthor, '外校不应出现帖子', 'audit-list-foreign-post');

        $page = $this->withToken($token)->getJson('/api/v1/admin/community/posts?audit_status=pending_review&page=1&page_size=1')
            ->assertOk()->assertJsonCount(1, 'data.items');
        $this->assertSame($edited->public_id, $page->json('data.items.0.id'), json_encode([
            'pending' => $pending->public_id,
            'published' => $published->public_id,
            'rejected' => $rejected->public_id,
            'edited' => $edited->public_id,
            'hidden' => $hidden->public_id,
            'deleted' => $deleted->public_id,
            'foreign' => $foreign->public_id,
            'response' => $page->json(),
        ], JSON_UNESCAPED_UNICODE));
        $page->assertJsonPath('data.items.0.audit_status', 'pending_review')
            ->assertJsonPath('data.items.0.latest_version.title', $currentTitle)
            ->assertJsonPath('data.items.0.current_version_no', 2)
            ->assertJsonPath('data.items.0.published_version_no', 1)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.stats.total', 6)
            ->assertJsonPath('data.stats.pending_review', 2)
            ->assertJsonPath('data.stats.published', 1)
            ->assertJsonPath('data.stats.rejected', 1)
            ->assertJsonPath('data.stats.hidden', 1)
            ->assertJsonPath('data.stats.deleted', 1);

        $this->withToken($token)->getJson('/api/v1/admin/community/posts?keyword='.urlencode($currentTitle).'&category_code=question')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $edited->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/community/posts?audit_status=deleted&id='.$deleted->public_id)
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.audit_status', 'deleted');
        $all = $this->withToken($token)->getJson('/api/v1/admin/community/posts?page_size=50')
            ->assertOk()->assertJsonCount(6, 'data.items');
        $this->assertNotContains($foreign->public_id, collect($all->json('data.items'))->pluck('id'));
        $this->assertSame('本校帖子作者', collect($all->json('data.items'))->firstWhere('id', $pending->public_id)['author']['nickname']);
    }

    public function test_post_batch_review_is_partially_successful_idempotent_audited_and_delete_reuses_moderation(): void
    {
        $ownCampus = $this->campus('audit-review-own', '批量审核本校');
        $foreignCampus = $this->campus('audit-review-foreign', '批量审核外校');
        [$admin, $token] = $this->admin('audit-review-agent', $ownCampus);
        $firstAuthor = $this->user($ownCampus, '第一位帖子作者');
        $secondAuthor = $this->user($ownCampus, '第二位帖子作者');
        $publishedAuthor = $this->user($ownCampus, '已审核帖子作者');
        $first = $this->createPost($firstAuthor, '批量通过第一帖', 'audit-review-first');
        $second = $this->createPost($secondAuthor, '批量通过第二帖', 'audit-review-second');
        $foreign = $this->createPost($this->user($foreignCampus, '跨校作者'), '跨校待审核帖子', 'audit-review-foreign-post');
        $already = $this->createPost($publishedAuthor, '已完成审核的帖子', 'audit-review-already');
        $this->content()->reviewPost($admin, $already, ['decision' => 'approve', 'note' => '预先完成审核。']);

        $batch = $this->withToken($token)->postJson('/api/v1/admin/community/posts/batch-review', [
            'ids' => [$first->public_id, $foreign->public_id, $second->public_id, $already->public_id],
            'decision' => 'approve',
            'note' => '批量审核本校待审帖子。',
        ])->assertOk();
        $batch->assertJsonPath('data.items.0.success', true)
            ->assertJsonPath('data.items.1.success', false);
        $this->assertSame('NOT_FOUND', $batch->json('data.items.1.code'), json_encode($batch->json(), JSON_UNESCAPED_UNICODE));
        $batch->assertJsonPath('data.items.2.success', true)
            ->assertJsonPath('data.items.3.success', false)
            ->assertJsonPath('data.items.3.code', 'REVIEW_ALREADY_FINISHED');

        $this->assertSame('published', $first->fresh()->status);
        $this->assertSame('published', $second->fresh()->status);
        $this->assertNotNull($first->fresh()->versions()->where('version_no', 1)->firstOrFail()->reviewed_at);
        $this->assertSame(10, $firstAuthor->assetAccount()->firstOrFail()->points_available);
        $this->assertSame(10, $secondAuthor->assetAccount()->firstOrFail()->points_available);
        $this->assertDatabaseCount('point_transactions', 3);
        $this->assertSame(2, DB::table('operation_logs')->where('action', 'community.post.approve')->count());

        $this->withToken($token)->postJson('/api/v1/admin/community/posts/batch-review', [
            'ids' => [$first->public_id], 'decision' => 'approve', 'note' => '重复审核必须返回冲突。',
        ])->assertOk()
            ->assertJsonPath('data.items.0.success', false)
            ->assertJsonPath('data.items.0.code', 'REVIEW_ALREADY_FINISHED');
        $this->assertDatabaseCount('point_transactions', 3);
        $this->assertSame(10, $firstAuthor->assetAccount()->firstOrFail()->points_available);

        $rejected = $this->createPost($secondAuthor, '单条驳回帖子', 'audit-review-single-reject');
        $this->withToken($token)->postJson('/api/v1/admin/community/posts/'.$rejected->public_id.'/review', [
            'decision' => 'reject', 'reason_code' => 'spam', 'note' => '单条驳回并记录合法原因码。',
        ])->assertOk()->assertJsonPath('data.audit_status', 'rejected');
        $this->assertNotNull($rejected->fresh()->versions()->where('version_no', 1)->firstOrFail()->reviewed_at);

        $deletePayload = ['decision' => 'delete', 'reason_code' => 'spam', 'note' => '后台确认删除违规帖子。'];
        $this->withToken($token)->withHeader('Idempotency-Key', 'admin-post-delete-001')
            ->postJson('/api/v1/admin/community/post/'.$first->public_id.'/moderate', $deletePayload)->assertOk();
        $deleted = CommunityPost::withTrashed()->findOrFail($first->id);
        $this->assertFalse($deleted->trashed(), 'moderation 删除以状态标记保留申诉和审计关联。');
        $this->assertSame('deleted', $deleted->status);
        $this->assertSame(0, $firstAuthor->assetAccount()->firstOrFail()->points_available);
        $this->assertDatabaseCount('moderation_actions', 1);
        $this->withToken($token)->withHeader('Idempotency-Key', 'admin-post-delete-001')
            ->postJson('/api/v1/admin/community/post/'.$first->public_id.'/moderate', $deletePayload)->assertOk();
        $this->assertDatabaseCount('moderation_actions', 1);
        $this->withToken($token)->getJson('/api/v1/admin/community/posts?audit_status=deleted')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $first->public_id);
    }

    public function test_post_review_cannot_republish_hidden_or_deleted_moderated_content(): void
    {
        $campus = $this->campus('audit-moderated-own', '处置后复审本校');
        [$admin, $token] = $this->admin('audit-moderated-agent', $campus);
        $author = $this->user($campus, '处置后复审作者');
        $hidden = $this->createPost($author, '隐藏前公开帖子', 'audit-moderated-hidden');
        $deleted = $this->createPost($author, '删除前公开帖子', 'audit-moderated-deleted');
        $originalTitles = [];

        foreach ([$hidden, $deleted] as $post) {
            $originalTitles[$post->id] = $post->title;
            $this->content()->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '先发布原始版本。']);
            $this->content()->editPost($author, $post->fresh(), [
                'title' => $post->title.'待审编辑版本',
                'content' => '这是已发布帖子的待审编辑版本，处置后不能通过审核重新公开。',
                'category_code' => 'guide',
            ]);
        }

        $this->withToken($token)->withHeader('Idempotency-Key', 'audit-moderated-hide-001')
            ->postJson('/api/v1/admin/community/post/'.$hidden->public_id.'/moderate', [
                'decision' => 'hide', 'reason_code' => 'spam', 'note' => '隐藏等待复审的帖子。',
            ])->assertOk();
        $this->withToken($token)->withHeader('Idempotency-Key', 'audit-moderated-delete-001')
            ->postJson('/api/v1/admin/community/post/'.$deleted->public_id.'/moderate', [
                'decision' => 'delete', 'reason_code' => 'spam', 'note' => '删除等待复审的帖子。',
            ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/community/posts/'.$hidden->public_id.'/review', [
            'decision' => 'approve', 'note' => '已隐藏内容不能重新发布。',
        ])->assertConflict()->assertJsonPath('code', 'REVIEW_STATE_CONFLICT');
        $this->withToken($token)->postJson('/api/v1/admin/community/posts/batch-review', [
            'ids' => [$deleted->public_id], 'decision' => 'approve', 'note' => '已删除内容不能重新发布。',
        ])->assertOk()
            ->assertJsonPath('data.items.0.success', false)
            ->assertJsonPath('data.items.0.code', 'REVIEW_STATE_CONFLICT');

        foreach (['hidden' => $hidden, 'deleted' => $deleted] as $status => $post) {
            $post->refresh();
            $this->assertSame($status, $post->status);
            $this->assertSame($originalTitles[$post->id], $post->title);
            $this->assertSame(1, $post->published_version_no);
            $this->assertSame('pending', $post->versions()->where('version_no', $post->current_version_no)->firstOrFail()->review_status);
        }
        $this->assertSame(2, DB::table('moderation_actions')->where('status', 'effective')->count());
    }

    public function test_comment_list_single_and_batch_review_keep_rewards_counts_notifications_and_conflicts_consistent(): void
    {
        $ownCampus = $this->campus('audit-comment-own', '评论审核本校');
        $foreignCampus = $this->campus('audit-comment-foreign', '评论审核外校');
        [$admin, $token] = $this->admin('audit-comment-agent', $ownCampus);
        $definitionId = DB::table('system_setting_definitions')->where('setting_key', 'community.comment_review_mode')->value('id');
        DB::table('system_setting_versions')->insert([
            'setting_definition_id' => $definitionId,
            'version' => 1,
            'value' => json_encode('pre_review', JSON_UNESCAPED_UNICODE),
            'source' => 'publish',
            'reason' => '测试评论人工审核流程',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::forget('system-setting:active:community.comment_review_mode');
        $postAuthor = $this->user($ownCampus, '评论所属帖子作者');
        $commenter = $this->user($ownCampus, '本校评论作者');
        $post = $this->createPost($postAuthor, '评论管理所属帖子', 'audit-comment-post');
        $post = $this->content()->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '发布评论测试帖。']);
        $first = $this->comment($commenter, $post, '第一条等待后台审核且长度满足积分奖励要求的评论。', 'audit-comment-first');
        $second = $this->comment($commenter, $post, '第二条等待后台审核且长度满足积分奖励要求的评论。', 'audit-comment-second');
        $foreignPost = $this->createPost($this->user($foreignCampus, '外校帖子作者'), '外校评论所属帖子', 'audit-comment-foreign-post');
        $foreignPost = $this->content()->reviewPost($admin, $foreignPost, ['decision' => 'approve', 'note' => '发布外校测试帖。']);
        $foreign = $this->comment($this->user($foreignCampus, '外校评论作者'), $foreignPost, '外校待审评论不应被本校管理员批量处理。', 'audit-comment-foreign');

        $this->withToken($token)->getJson('/api/v1/admin/community/comments?audit_status=pending_review&page=1&page_size=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $second->public_id)
            ->assertJsonPath('data.items.0.post_id', $post->public_id)
            ->assertJsonPath('data.items.0.post_title', '评论管理所属帖子')
            ->assertJsonPath('data.items.0.reviewed_at', null)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.stats.total', 2)
            ->assertJsonPath('data.stats.pending_review', 2);

        $this->withToken($token)->postJson('/api/v1/admin/community/comments/batch-review', [
            'ids' => [$first->public_id, $foreign->public_id, $second->public_id],
            'decision' => 'approve',
            'note' => '批量通过本校合规评论。',
        ])->assertOk()
            ->assertJsonPath('data.items.0.success', true)
            ->assertJsonPath('data.items.1.success', false)
            ->assertJsonPath('data.items.1.code', 'NOT_FOUND')
            ->assertJsonPath('data.items.2.success', true);
        $this->assertSame('published', $first->fresh()->status);
        $this->assertSame('published', $second->fresh()->status);
        $this->assertNotNull($first->fresh()->reviewed_at);
        $this->assertSame(2, $post->fresh()->comment_count);
        $this->assertSame(4, $commenter->assetAccount()->firstOrFail()->points_available);
        $this->assertSame(2, DB::table('notifications')->where('user_id', $commenter->id)->where('scene_code', 'comment_review_approved')->count());
        $this->assertSame(2, DB::table('operation_logs')->where('action', 'community.comment.approve')->count());

        $this->withToken($token)->postJson('/api/v1/admin/community/comments/batch-review', [
            'ids' => [$first->public_id, $second->public_id], 'decision' => 'approve', 'note' => '重复审核评论不能重复奖励。',
        ])->assertOk()
            ->assertJsonPath('data.items.0.code', 'REVIEW_ALREADY_FINISHED')
            ->assertJsonPath('data.items.1.code', 'REVIEW_ALREADY_FINISHED');
        $this->assertSame(2, $post->fresh()->comment_count);
        $this->assertSame(4, $commenter->assetAccount()->firstOrFail()->points_available);

        $third = $this->comment($commenter, $post, '第三条评论用于验证单条驳回原因码和审核时间。', 'audit-comment-third');
        $this->withToken($token)->postJson('/api/v1/admin/community/comments/'.$third->public_id.'/review', [
            'decision' => 'reject', 'reason_code' => 'content_not_compliant', 'note' => '非法原因码必须拒绝。',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/admin/community/comments/'.$third->public_id.'/review', [
            'decision' => 'reject', 'reason_code' => 'spam', 'note' => '单条驳回垃圾评论。',
        ])->assertOk()
            ->assertJsonPath('data.audit_status', 'rejected')
            ->assertJsonPath('data.review_reason_code', 'spam');
        $this->assertNotNull($third->fresh()->reviewed_at);
        $this->assertSame(2, $post->fresh()->comment_count);
        $this->assertSame(4, $commenter->assetAccount()->firstOrFail()->points_available);
        $this->assertDatabaseHas('notifications', ['user_id' => $commenter->id, 'scene_code' => 'comment_review_rejected']);
    }

    public function test_security_records_support_real_filters_stats_and_campus_isolation(): void
    {
        $ownCampus = $this->campus('audit-record-own', '机审记录本校');
        $foreignCampus = $this->campus('audit-record-foreign', '机审记录外校');
        [, $token] = $this->admin('audit-record-agent', $ownCampus);
        $ownAuthor = $this->user($ownCampus, '机审记录作者');
        $contentPublicId = str_repeat('A', 26);
        $this->securityLog($ownAuthor, ['verdict' => 'pass', 'provider' => 'wechat', 'risk_level' => 'none', 'content_snapshot' => '正常内容快照']);
        $review = $this->securityLog($ownAuthor, [
            'verdict' => 'review',
            'provider' => 'aliyun',
            'risk_level' => 'medium',
            'risk_labels' => ['suspicious'],
            'content_public_id' => $contentPublicId,
            'content_version_no' => 2,
            'content_snapshot' => '人工复审快照关键词',
        ]);
        $this->securityLog($ownAuthor, ['verdict' => 'skipped', 'provider' => 'aliyun', 'risk_level' => null, 'skip_reason' => 'not-configured']);
        $foreign = $this->securityLog($this->user($foreignCampus, '外校机审作者'), [
            'verdict' => 'block', 'provider' => 'aliyun', 'risk_level' => 'high', 'content_snapshot' => '外校拦截快照',
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/community/content-security/records?provider=aliyun&verdict=review&risk_level=medium&keyword='.urlencode('人工复审'))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $review->public_id)
            ->assertJsonPath('data.items.0.content_id', $contentPublicId)
            ->assertJsonPath('data.items.0.content_version_no', 2)
            ->assertJsonPath('data.items.0.author.nickname', '机审记录作者')
            ->assertJsonPath('data.items.0.risk_labels.0', 'suspicious')
            ->assertJsonPath('data.stats.total', 3)
            ->assertJsonPath('data.stats.pass', 1)
            ->assertJsonPath('data.stats.review', 1)
            ->assertJsonPath('data.stats.block', 0)
            ->assertJsonPath('data.stats.skipped', 1);

        $all = $this->withToken($token)->getJson('/api/v1/admin/community/content-security/records')
            ->assertOk()->assertJsonCount(3, 'data.items');
        $this->assertNotContains($foreign->public_id, collect($all->json('data.items'))->pluck('id'));
    }

    public function test_admin_can_search_campus_authors_and_publish_post_directly_with_security_link_and_audit(): void
    {
        $ownCampus = $this->campus('admin-post-own', '后台发帖本校');
        $foreignCampus = $this->campus('admin-post-foreign', '后台发帖外校');
        [$admin, $token] = $this->admin('admin-post-agent', $ownCampus);
        $author = $this->user($ownCampus, '后台发帖作者01');
        $foreignAuthor = $this->user($foreignCampus, '外校发帖作者');

        $authors = $this->withToken($token)->getJson('/api/v1/admin/community/post-authors?keyword='.urlencode('后台发帖'))
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $author->public_id)
            ->assertJsonPath('data.items.0.nickname', '后台发帖作者01');
        $this->assertNotContains($foreignAuthor->public_id, collect($authors->json('data.items'))->pluck('id'));

        $created = $this->withToken($token)->postJson('/api/v1/admin/community/posts', [
            'author_id' => $author->public_id,
            'title' => '后台运营发布的迎新公告',
            'content' => '这是后台运营代发并直接发布的迎新公告正文，长度满足社区发布规则。',
            'category_code' => 'guide',
            'location_name' => '学生活动中心',
            'admin_note' => '迎新季运营公告，按计划直接发布。',
        ])->assertCreated()
            ->assertJsonPath('data.audit_status', 'published')
            ->assertJsonPath('data.title', '后台运营发布的迎新公告')
            ->assertJsonPath('data.author.nickname', '后台发帖作者01');

        $post = CommunityPost::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->assertSame('published', $post->status);
        $this->assertSame($ownCampus->id, $post->campus_id);
        $this->assertSame($admin->id, $post->reviewed_by_admin_id);
        $this->assertSame(1, $post->published_version_no);
        $this->assertSame(1, $post->current_version_no);
        $this->assertSame('approved', $post->versions()->where('version_no', 1)->firstOrFail()->review_status);
        $this->assertCount(2, CommunityContentSecurityLog::query()->where('content_public_id', $post->public_id)->get());
        $this->assertDatabaseMissing('point_transactions', ['user_id' => $author->id]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.post.admin_create', 'subject_id' => $post->public_id]);

        $this->withToken($token)->postJson('/api/v1/admin/community/posts', [
            'author_id' => $foreignAuthor->public_id,
            'title' => '跨校作者不能后台代发',
            'content' => '跨校作者不应出现在当前校区的可选作者中，也不能被后台代发发布。',
            'category_code' => 'guide',
        ])->assertNotFound()->assertJsonPath('code', 'AUTHOR_NOT_FOUND');
    }

    public function test_admin_publish_is_rejected_when_machine_audit_blocks_content(): void
    {
        $campus = $this->campus('admin-post-block', '后台发帖拦截');
        [, $token] = $this->admin('admin-post-block-agent', $campus);
        $author = $this->user($campus, '被拦截作者');
        $security = $this->mock(CommunityContentSecurityService::class);
        $security->shouldReceive('scanText')->once()->andReturn([
            'verdict' => 'block',
            'label' => 20001,
            'provider' => 'aliyun',
            'source' => 'aliyun',
            'record_id' => 1,
        ]);

        $this->withToken($token)->postJson('/api/v1/admin/community/posts', [
            'author_id' => $author->public_id,
            'title' => '包含违规内容的帖子标题',
            'content' => '这条帖子正文会被机审拦截，不能通过后台代发直接发布。',
            'category_code' => 'guide',
        ])->assertUnprocessable()->assertJsonPath('code', 'POST_CREATE_REJECTED');

        $this->assertDatabaseMissing('community_posts', ['user_id' => $author->id]);
    }

    public function test_admin_projection_reveals_the_real_author_of_an_anonymous_post(): void
    {
        $campus = $this->campus('anonymous-admin-campus', '匿名审核本校');
        [$admin, $token] = $this->admin('anonymous-admin-agent', $campus);
        $author = $this->user($campus, '后台可见真实作者');
        $post = $this->content()->createPost($author, [
            'title' => '匿名帖子后台作者核验',
            'content' => '这是用于验证匿名帖子在审核后台保留真实作者身份的完整帖子正文内容。',
            'category_code' => 'experience',
            'visibility' => 'anonymous',
        ], 'anonymous-admin-review-post');
        $post->forceFill(['campus_id' => $author->campus_id])->save();
        $this->content()->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '匿名后台测试审核通过。']);

        $this->getJson('/api/v1/community/posts/'.$post->public_id)
            ->assertOk()
            ->assertJsonPath('data.author.id', null)
            ->assertJsonPath('data.author.nickname', '匿名用户');
        $this->withToken($token)->getJson('/api/v1/admin/community/posts?id='.$post->public_id)
            ->assertOk()
            ->assertJsonPath('data.items.0.visibility', 'anonymous')
            ->assertJsonPath('data.items.0.author.id', $author->public_id)
            ->assertJsonPath('data.items.0.author.nickname', '后台可见真实作者');
    }

    private function content(): CommunityContentService
    {
        return app(CommunityContentService::class);
    }

    private function campus(string $code, string $name): Campus
    {
        return Campus::query()->create(['code' => $code, 'name' => $name, 'status' => 'active']);
    }

    private function user(Campus $campus, string $nickname): User
    {
        return User::factory()->create(['campus_id' => $campus->id, 'nickname' => $nickname, 'status' => 'active']);
    }

    /** @return array{AdminUser,string} */
    private function admin(string $account, Campus $campus): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'campus_agent')->value('id'));
        $admin->campuses()->attach($campus->id, ['is_primary' => true]);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    private function createPost(User $author, string $title, string $key, string $category = 'experience'): CommunityPost
    {
        $post = $this->content()->createPost($author, [
            'title' => $title,
            'content' => '这是用于内容审核后台真实接口测试的帖子正文，长度满足社区发布规则。',
            'category_code' => $category,
        ], $key);
        // 直接调用服务不会经过 ResolveCampusScope，补齐真实发布请求会写入的校区归属。
        $post->forceFill(['campus_id' => $author->campus_id])->save();

        return $post;
    }

    private function comment(User $author, CommunityPost $post, string $content, string $key): CommunityComment
    {
        return $this->content()->createComment($author, $post, ['content' => $content], $key);
    }

    private function securityLog(User $author, array $attributes): CommunityContentSecurityLog
    {
        return CommunityContentSecurityLog::query()->create([
            'campus_id' => $author->campus_id,
            'user_id' => $author->id,
            'content_type' => 'post',
            'content_field' => 'content',
            'subject_snapshot' => '机审记录主题',
            'content_snapshot' => '默认机审快照',
            'content_hash' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
            'provider' => 'wechat',
            'verdict' => 'pass',
            'risk_labels' => [],
            ...$attributes,
        ]);
    }
}
