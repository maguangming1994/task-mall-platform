<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Community\Http\Controllers\CommunityEngagementController;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Notification\Models\Notification;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampusCommunityActivityFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('s3');
    }

    public function test_post_likes_are_idempotent_and_feed_contains_count_and_viewer_state(): void
    {
        [$token, $user] = $this->phoneLoginWithCommunityConsent('13800138201');
        $admin = AdminUser::query()->create(['account' => 'like-reviewer', 'display_name' => '点赞测试审核员', 'password' => 'AdminPassword12345!']);
        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '校园图书馆自习经验分享',
            'content' => '分享图书馆不同楼层的开放时间、插座位置和安静程度，方便同学选择合适座位。',
            'category_code' => 'experience',
        ], 'campus-like-post-001');
        $post = app(CommunityContentService::class)->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '内容合规。']);

        $this->withToken($token)->postJson("/api/v1/community/posts/{$post->public_id}/like")
            ->assertOk()->assertJsonPath('data.like_count', 1)->assertJsonPath('data.liked_by_me', true);
        $this->withToken($token)->postJson("/api/v1/community/posts/{$post->public_id}/like")
            ->assertOk()->assertJsonPath('data.like_count', 1);
        $this->withToken($token)->getJson('/api/v1/community/posts')
            ->assertOk()->assertJsonPath('data.items.0.like_count', 1)->assertJsonPath('data.items.0.liked_by_me', true);
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/community/posts')
            ->assertOk()->assertJsonPath('data.items.0.liked_by_me', false);
        $this->withToken($token)->deleteJson("/api/v1/community/posts/{$post->public_id}/like")
            ->assertOk()->assertJsonPath('data.like_count', 0)->assertJsonPath('data.liked_by_me', false);
        $this->withToken($token)->deleteJson("/api/v1/community/posts/{$post->public_id}/like")
            ->assertOk()->assertJsonPath('data.like_count', 0);
        $this->assertDatabaseCount('community_post_likes', 0);
        $this->assertSame(0, Notification::query()->where('scene_code', 'community_post_liked')->count());

        $classmate = User::factory()->create(['status' => 'active', 'nickname' => '校园同学']);
        $likeRequest = Request::create('/api/v1/community/posts/'.$post->public_id.'/like', 'POST');
        $likeRequest->setUserResolver(fn () => $classmate);
        $controller = app(CommunityEngagementController::class);
        $controller->like($likeRequest, $post->fresh(), app(NotificationService::class));
        $controller->like($likeRequest, $post->fresh(), app(NotificationService::class));
        $this->assertSame(1, $post->fresh()->like_count);
        $notification = Notification::query()->where('scene_code', 'community_post_liked')->sole();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame(['post_id' => $post->public_id], $notification->data);

        $controller->unlike($likeRequest, $post->fresh());
        $controller->like($likeRequest, $post->fresh(), app(NotificationService::class));
        $this->assertSame(1, Notification::query()->where('scene_code', 'community_post_liked')->count());
    }

    public function test_published_comments_and_replies_notify_the_affected_classmate_once(): void
    {
        $author = User::factory()->create(['status' => 'active', 'nickname' => '发帖同学']);
        $commenter = User::factory()->create(['status' => 'active', 'nickname' => '评论同学']);
        $admin = AdminUser::query()->create(['account' => 'interaction-reviewer', 'display_name' => '互动审核员', 'password' => 'AdminPassword12345!']);
        $service = app(CommunityContentService::class);
        $post = $service->createPost($author, [
            'title' => '校园社团招新经验分享',
            'content' => '整理社团招新的时间安排、报名材料和面试准备建议，方便新同学快速了解校园社团。',
            'category_code' => 'experience',
        ], 'interaction-notification-post');
        $post = $service->reviewPost($admin, $post, ['decision' => 'approve', 'note' => '内容合规。']);

        $comment = $service->createComment($commenter, $post, ['content' => '这份社团报名经验很实用，感谢认真整理。'], 'interaction-comment-001');
        $same = $service->createComment($commenter, $post, ['content' => '重复请求不会生成第二条评论。'], 'interaction-comment-001');
        $this->assertSame($comment->public_id, $same->public_id);
        $commentNotice = Notification::query()->where('scene_code', 'community_post_commented')->sole();
        $this->assertSame($author->id, $commentNotice->user_id);
        $this->assertSame($post->public_id, $commentNotice->data['post_id']);
        $this->assertSame($comment->public_id, $commentNotice->data['comment_id']);

        $reply = $service->createComment($author, $post, [
            'content' => '谢谢支持，有其他问题也可以继续在帖子里交流。',
            'parent_comment_id' => $comment->public_id,
        ], 'interaction-reply-001');
        $service->createComment($author, $post, [
            'content' => '重复回复不会新增消息。',
            'parent_comment_id' => $comment->public_id,
        ], 'interaction-reply-001');
        $replyNotice = Notification::query()->where('scene_code', 'community_comment_replied')->sole();
        $this->assertSame($commenter->id, $replyNotice->user_id);
        $this->assertSame($reply->public_id, $replyNotice->data['comment_id']);
        $this->assertSame($comment->public_id, $replyNotice->data['parent_comment_id']);

        $service->createComment($author, $post, ['content' => '发帖人补充一条自己的说明，不应该提醒自己。'], 'interaction-self-comment');
        $this->assertSame(1, Notification::query()->where('scene_code', 'community_post_commented')->count());

        $pending = $service->createComment($commenter, $post, ['content' => '请加微信联系我获取更多社团报名材料。'], 'interaction-reviewed-comment');
        $this->assertSame('pending_review', $pending->status);
        $this->assertSame(1, Notification::query()->where('scene_code', 'community_post_commented')->count());
        $service->reviewComment($admin, $pending, ['decision' => 'approve', 'note' => '人工确认后允许公开。']);
        $this->assertSame(2, Notification::query()->where('scene_code', 'community_post_commented')->count());
    }

    public function test_activity_review_join_leave_capacity_and_close_form_a_complete_flow(): void
    {
        [$creatorToken, $creator] = $this->phoneLoginWithCommunityConsent('13800138202');
        [$participantToken] = $this->phoneLoginWithCommunityConsent('13800138203');
        [$overflowToken] = $this->phoneLoginWithCommunityConsent('13800138204');
        $adminToken = $this->adminToken('campus-activity-reviewer', 'community_reviewer');
        $file = $this->cleanCommunityImage($creator, 'campus-study.png');
        $payload = [
            'category_code' => 'study',
            'title' => '周三晚图书馆结伴自习',
            'details' => '一起复习期末考试，保持安静，活动仅限校内公开区域，不收取任何费用。',
            'image_file_ids' => [$file->public_id],
            'location' => '校图书馆三楼公共自习区',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'participant_limit' => 2,
        ];

        $created = $this->withToken($creatorToken)->withHeader('Idempotency-Key', 'campus-activity-create-001')
            ->postJson('/api/v1/community/activities', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.participant_count', 1);
        $activityId = $created->json('data.id');
        $this->withToken($creatorToken)->withHeader('Idempotency-Key', 'campus-activity-create-001')
            ->postJson('/api/v1/community/activities', $payload)->assertCreated()->assertJsonPath('data.id', $activityId);
        $this->getJson('/api/v1/community/activities')->assertOk()->assertJsonCount(0, 'data.items');

        $this->withToken($adminToken)->getJson('/api/v1/admin/community/activities?status=pending_review')
            ->assertOk()->assertJsonPath('data.items.0.id', $activityId)
            ->assertJsonPath('data.items.0.images.0.url', "/admin/community/activities/media/{$file->public_id}");
        $this->withToken($adminToken)->get("/api/v1/admin/community/activities/media/{$file->public_id}")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withToken($adminToken)->postJson("/api/v1/admin/community/activities/{$activityId}/review", [
            'decision' => 'approve', 'note' => '校内公开活动，审核通过。',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/community/activities?category=study')
            ->assertOk()->assertJsonPath('data.items.0.images.0.file_id', $file->public_id)
            ->assertJsonPath('data.items.0.remaining_slots', 1);
        $this->withHeaders(['Authorization' => ''])->get("/api/v1/community/activities/media/{$file->public_id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->withToken($participantToken)->postJson("/api/v1/community/activities/{$activityId}/join")
            ->assertOk()->assertJsonPath('data.participant_count', 2)->assertJsonPath('data.joined_by_me', true);
        $this->withToken($participantToken)->postJson("/api/v1/community/activities/{$activityId}/join")
            ->assertOk()->assertJsonPath('data.participant_count', 2);
        $this->withToken($overflowToken)->postJson("/api/v1/community/activities/{$activityId}/join")
            ->assertUnprocessable()->assertJsonPath('details.errors.activity.0', '活动名额已满。');
        $participantId = $this->withToken($adminToken)->getJson('/api/v1/admin/community/activities')
            ->assertOk()->assertJsonPath('data.items.0.participants.1.status', 'joined')
            ->json('data.items.0.participants.1.participant_id');
        $this->withToken($adminToken)->postJson("/api/v1/admin/community/activities/{$activityId}/participants/{$participantId}/remove", [
            'reason' => '测试异常参与人移出活动。',
        ])->assertOk()->assertJsonPath('data.participant_count', 1)
            ->assertJsonPath('data.participants.1.status', 'removed');
        $this->withToken($participantToken)->postJson("/api/v1/community/activities/{$activityId}/join")
            ->assertUnprocessable()->assertJsonPath('details.errors.activity.0', '你已被活动管理员移出，如需恢复请联系平台客服。');
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.activity.participant.remove', 'subject_id' => $activityId]);
        $this->assertDatabaseHas('notifications', ['scene_code' => 'activity_participant_removed']);
        $this->withToken($participantToken)->getJson('/api/v1/community/my-activities?scope=joined')
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->withToken($creatorToken)->getJson('/api/v1/community/my-activities?scope=created')
            ->assertOk()->assertJsonPath('data.items.0.id', $activityId);

        $this->withToken($participantToken)->deleteJson("/api/v1/community/activities/{$activityId}/join")
            ->assertOk()->assertJsonPath('data.participant_count', 1)->assertJsonPath('data.joined_by_me', false);
        $this->withToken($participantToken)->deleteJson("/api/v1/community/activities/{$activityId}/join")
            ->assertOk()->assertJsonPath('data.participant_count', 1);
        $this->withToken($adminToken)->postJson("/api/v1/admin/community/activities/{$activityId}/close", ['reason' => '场地临时关闭。'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.activity.review', 'subject_id' => $activityId]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.activity.close', 'subject_id' => $activityId]);
        $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/community/activities/{$activityId}")->assertNotFound();
    }

    public function test_homepage_has_campus_defaults_and_reviewer_can_publish_visual_configuration(): void
    {
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/community/home')
            ->assertOk()->assertJsonPath('data.banner.title', '校园互助社区')
            ->assertJsonPath('data.quick_entries.1.target', 'activities');
        $adminToken = $this->adminToken('community-config-reviewer', 'community_reviewer');
        $config = [
            'banner' => ['title' => '青春校园圈', 'subtitle' => '发现同校伙伴', 'image_url' => '', 'link_type' => 'activities', 'accent_color' => '#24D98A'],
            'quick_entries' => [
                ['id' => 'activity', 'label' => '发起组团', 'description' => '找同校伙伴', 'icon_url' => '', 'target' => 'activities', 'enabled' => true, 'color' => '#FF5964', 'sort_order' => 10],
            ],
            'hot_topics' => [
                ['id' => 'study', 'label' => '#期末自习', 'target' => 'study', 'enabled' => true, 'color' => '#4D9CFF'],
            ],
            'reason' => '启用校园高饱和视觉方案。',
        ];
        $updated = $this->withToken($adminToken)->putJson('/api/v1/admin/community/config', $config)
            ->assertOk()->assertJsonPath('data.banner.accent_color', '#24D98A')
            ->assertJsonPath('data.quick_entries.0.sort_order', 10);
        $this->assertNotNull($updated->json('data.version'));
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.homepage.publish', 'subject_id' => 'community.homepage']);
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/community/home')->assertOk()->assertJsonPath('data.hot_topics.0.label', '#期末自习');
    }

    public function test_sensitive_activity_content_is_rejected_before_review(): void
    {
        [$token] = $this->phoneLoginWithCommunityConsent('13800138205');
        $this->withToken($token)->withHeader('Idempotency-Key', 'campus-sensitive-activity-001')
            ->postJson('/api/v1/community/activities', [
                'category_code' => 'other', 'title' => '校内活动联系方式',
                'details' => '请加微信参与代刷服务并获得返现。', 'location' => '校内',
                'starts_at' => now()->addDay()->toIso8601String(), 'participant_limit' => 5,
            ])->assertUnprocessable()->assertJsonPath('details.errors.details.0', '活动内容包含平台禁止发布的信息，请修改后重试。');
        $this->assertDatabaseCount('community_activities', 0);
    }

    private function adminToken(string $account, string $role): string
    {
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');
    }

    private function cleanCommunityImage(User $user, string $name): UploadedFile
    {
        $path = 'quarantine/community/'.$name;
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Storage::disk('s3')->put($path, $bytes);

        return UploadedFile::query()->create([
            'owner_user_id' => $user->id, 'purpose' => 'community', 'original_name' => $name,
            'extension' => 'png', 'declared_mime' => 'image/png', 'detected_mime' => 'image/png',
            'declared_size' => strlen($bytes), 'actual_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'disk' => 's3', 'quarantine_path' => $path, 'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', 'campus-file-'.$name), 'ticket_expires_at' => now()->addHour(),
            'uploaded_at' => now(), 'scanned_at' => now(),
        ]);
    }

    /** @return array{0:string,1:User} */
    private function phoneLoginWithCommunityConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'campus-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        $user = User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail();
        $this->bindTestCampus($user);
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=community')->assertOk()->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => "campus-consent-{$phone}-{$index}", 'X-Client-Platform' => 'h5-browser'])
                ->postJson('/api/v1/me/legal-consents', ['legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false])
                ->assertCreated();
        }

        return [$token, $user];
    }
}
