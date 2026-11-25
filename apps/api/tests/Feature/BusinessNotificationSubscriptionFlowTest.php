<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Notification\Models\NotificationBusinessEvent;
use App\Modules\Notification\Models\WechatSubscriptionGrant;
use App\Modules\Notification\Services\NotificationBusinessEventService;
use App\Modules\Notification\Services\NotificationSceneConfigService;
use App\Modules\Notification\Services\NotificationSceneRegistry;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Notification\Services\WechatSubscriptionGrantService;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BusinessNotificationSubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_business_scene_uses_published_template_is_idempotent_and_rolls_back_with_business_transaction(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'notification-template-owner', 'display_name' => '通知管理员', 'password' => 'AdminPassword12345!']);
        $task = Task::query()->create([
            'created_by_admin_id' => $admin->id,
            'status' => 'published',
            'current_version_no' => 1,
            'published_version_no' => 1,
            'idempotency_key_hash' => hash('sha256', 'notification-scene-task'),
            'published_at' => now(),
        ]);
        $version = TaskVersion::query()->create([
            'task_id' => $task->id,
            'version_no' => 1,
            'status' => 'published',
            'change_type' => 'initial',
            'changed_fields' => [],
            'content' => [],
            'created_by_admin_id' => $admin->id,
            'published_at' => now(),
        ]);
        $claim = TaskClaim::withoutEvents(fn () => TaskClaim::query()->create([
            'public_id' => (string) Str::ulid(),
            'task_id' => $task->id,
            'task_version_id' => $version->id,
            'user_id' => $user->id,
            'enrollment_source' => 'manual',
            'cycle_key' => 'notification-scene-cycle',
            'task_snapshot' => [],
            'status' => 'claimed',
            'slot_status' => 'reserved',
            'completion_deadline_at' => now()->addDay(),
            'claim_idempotency_key_hash' => hash('sha256', 'notification-scene-claim'),
        ]));
        $notifications = app(NotificationService::class);
        $notifications->saveTemplate($admin, [
            'scene_code' => 'task_claimed',
            'channel' => 'in_app',
            'subject' => '已领取：{{task_title}}',
            'body' => '请在 {{deadline}} 前处理，领取编号 {{task_claim_id}}。',
            'allowed_variables' => ['task_title', 'deadline', 'task_claim_id'],
            'provider_template_id' => null,
            'publish' => true,
        ]);
        $events = app(NotificationBusinessEventService::class);
        $variables = ['task_title' => '校园拍摄', 'deadline' => '2026-08-30 18:00', 'task_claim_id' => $claim->public_id];

        $first = $events->record($user, 'task_claimed', $variables, 'task-claim:'.$claim->public_id, ['task_claim_id' => $claim->public_id]);
        $same = $events->record($user, 'task_claimed', $variables, 'task-claim:'.$claim->public_id, ['task_claim_id' => $claim->public_id]);
        $this->assertSame($first->public_id, $same->public_id);
        $this->assertDatabaseCount('notification_business_events', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'scene_code' => 'task_claimed',
            'title' => '已领取：校园拍摄',
            'body' => '请在 2026-08-30 18:00 前处理，领取编号 '.$claim->public_id.'。',
        ]);

        try {
            DB::transaction(function () use ($events, $user, $variables): void {
                $events->record($user, 'task_claimed', $variables, 'task-claim:ROLLBACK', ['task_claim_id' => 'ROLLBACK']);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Expected rollback verifies that the outbox event shares the business transaction.
        }
        $this->assertDatabaseCount('notification_business_events', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_post_version_statuses_are_observed_after_commit_without_duplicate_hard_coded_notifications(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $post = CommunityPost::query()->create([
            'user_id' => $user->id,
            'title' => '校园互助帖',
            'content' => '正文',
            'category_code' => 'help',
            'status' => 'pending_review',
            'idempotency_key_hash' => hash('sha256', 'post-observer'),
        ]);
        $version = CommunityPostVersion::query()->create([
            'community_post_id' => $post->id,
            'version_no' => 1,
            'title' => $post->title,
            'content' => $post->content,
            'category_code' => 'help',
            'review_status' => 'pending',
            'rule_snapshot' => [],
        ]);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'scene_code' => 'post_review_pending']);

        $version->update(['review_status' => 'rejected', 'review_note' => '请补充活动时间。', 'reviewed_at' => now()]);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'scene_code' => 'post_review_rejected']);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_auto_approved_post_version_uses_publish_scene_without_consuming_review_subscription(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $post = CommunityPost::query()->create([
            'user_id' => $user->id,
            'title' => '自动发布帖子',
            'content' => '满足自动发布规则的内容。',
            'category_code' => 'help',
            'status' => 'published',
            'idempotency_key_hash' => hash('sha256', 'post-auto-published'),
        ]);
        CommunityPostVersion::query()->create([
            'community_post_id' => $post->id,
            'version_no' => 1,
            'title' => $post->title,
            'content' => $post->content,
            'category_code' => 'help',
            'review_status' => 'approved',
            'rule_snapshot' => ['auto_publish' => true],
        ]);

        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'scene_code' => 'post_published']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'scene_code' => 'post_review_approved']);
        // 发布成功场景也会创建微信渠道投递，但没有订阅授权时应跳过，且不消耗审核场景的授权。
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'wechat_mini', 'status' => 'skipped']);
        $this->assertDatabaseCount('wechat_subscription_grants', 0);
    }

    public function test_community_interaction_scenes_are_configurable_for_wechat_channels(): void
    {
        $registry = app(NotificationSceneRegistry::class);

        foreach (['community_post_commented', 'community_comment_replied'] as $sceneCode) {
            $this->assertTrue($registry->has($sceneCode));
            $scene = $registry->get($sceneCode);
            $this->assertSame('community', $scene['domain']);
            $this->assertContains('wechat_mini', $scene['default_channels']);
            $this->assertContains('wechat_oa', $scene['default_channels']);
            $this->assertContains('comment_author', $scene['allowed_variables']);
            $this->assertContains('comment_excerpt', $scene['allowed_variables']);
            $this->assertSame('pages/community-detail/index?id={{post_id}}', $scene['deep_link']);
        }
    }

    public function test_post_publish_and_review_approved_scenes_support_wechat_channels(): void
    {
        $registry = app(NotificationSceneRegistry::class);

        foreach (['post_published', 'post_review_approved'] as $sceneCode) {
            $scene = $registry->get($sceneCode);
            $this->assertContains('wechat_mini', $scene['default_channels']);
            $this->assertContains('wechat_oa', $scene['default_channels']);
        }
    }

    public function test_wechat_subscription_grant_is_reserved_once_and_delivery_calls_official_api_with_frozen_template_payload(): void
    {
        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'wx1234567890abcdef');
        config()->set('wechat_auth.mini.app_secret', 'test-mini-secret');
        Cache::clear();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'mini-access-token', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/subscribe/send*' => Http::response(['errcode' => 0, 'errmsg' => 'ok', 'msgid' => 'wechat-message-001']),
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $appIdHash = hash('sha256', 'wx1234567890abcdef');
        $identity = UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'wechat_mini',
            'provider_user_id' => hash('sha256', 'mini-openid-001'),
            'provider_user_value' => 'mini-openid-001',
            'metadata' => ['app_id_hash' => $appIdHash],
        ]);
        WechatSubscriptionGrant::query()->create([
            'user_id' => $user->id,
            'user_identity_id' => $identity->id,
            'provider_app_id_hash' => $appIdHash,
            'provider_template_id' => 'TASK_STATUS_TEMPLATE',
            'status' => 'accepted',
            'available_count' => 1,
            'last_requested_at' => now(),
            'last_accepted_at' => now(),
        ]);
        UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'wechat_mini',
            'provider_user_id' => hash('sha256', 'mini-openid-002'),
            'provider_user_value' => 'mini-openid-002',
            'metadata' => ['app_id_hash' => $appIdHash],
        ]);
        $admin = AdminUser::query()->create(['account' => 'wechat-template-owner', 'display_name' => '微信模板管理员', 'password' => 'AdminPassword12345!']);
        $notifications = app(NotificationService::class);
        $notifications->preference($user)->update(['wechat_enabled' => true, 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '00:00']);
        $notifications->saveTemplate($admin, [
            'scene_code' => 'task_completed',
            'channel' => 'wechat_mini',
            'subject' => '任务状态更新',
            'body' => '{"thing1":"{{task_title}}","phrase2":"{{status_text}}","time3":"{{occurred_at}}"}',
            'allowed_variables' => ['task_title', 'status_text', 'occurred_at'],
            'provider_template_id' => 'TASK_STATUS_TEMPLATE',
            'publish' => true,
        ]);

        $longTitle = 'Library "volunteer" briefing room';
        app(NotificationBusinessEventService::class)->record($user, 'task_completed', [
            'task_title' => $longTitle,
            'status_text' => '审核通过',
            'task_claim_id' => 'CLAIM-WECHAT-1',
            'task_id' => 'TASK-WECHAT-1',
            'occurred_at' => '2026-08-24 12:00',
        ], 'task:CLAIM-WECHAT-1:completed', ['task_claim_id' => 'CLAIM-WECHAT-1']);

        $this->assertDatabaseHas('wechat_subscription_grants', ['user_id' => $user->id, 'provider_template_id' => 'TASK_STATUS_TEMPLATE', 'status' => 'consumed', 'available_count' => 0]);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'wechat_mini', 'status' => 'pending']);
        $this->assertSame(1, $notifications->deliverPending());
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'wechat_mini', 'status' => 'sent', 'provider_message_id' => 'wechat-message-001']);
        $sent = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.weixin.qq.com/cgi-bin/message/subscribe/send'))->first();
        $this->assertNotNull($sent);
        /** @var Request $messageRequest */
        $messageRequest = $sent[0];
        $this->assertStringContainsString('access_token=mini-access-token', $messageRequest->url());
        $this->assertSame('mini-openid-001', $messageRequest['touser']);
        $this->assertSame('TASK_STATUS_TEMPLATE', $messageRequest['template_id']);
        $this->assertSame('pages/task-claim/index?id=CLAIM-WECHAT-1', $messageRequest['page']);
        $this->assertSame(rtrim(mb_substr($longTitle, 0, 20)), $messageRequest['data']['thing1']['value']);
        $this->assertSame('审核通过', $messageRequest['data']['phrase2']['value']);
    }

    public function test_official_account_subscription_delivery_uses_oa_openid_and_absolute_url(): void
    {
        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.official_account.app_id', 'wxoa1234567890abcd');
        config()->set('wechat_auth.official_account.app_secret', 'test-oa-secret');
        config()->set('app.url', 'https://rwsc.example.cn');
        Cache::clear();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'oa-access-token', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/message/subscribe/bizsend*' => Http::response(['errcode' => 0, 'errmsg' => 'ok', 'msgid' => 'oa-message-001']),
        ]);
        $user = User::factory()->create(['status' => 'active']);
        UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'wechat_oa',
            'provider_user_id' => hash('sha256', 'oa-openid-001'),
            'provider_user_value' => 'oa-openid-001',
            'metadata' => ['app_id_hash' => hash('sha256', 'wxoa1234567890abcd')],
        ]);
        $admin = AdminUser::query()->create(['account' => 'oa-template-owner', 'display_name' => '公众号模板管理员', 'password' => 'AdminPassword12345!']);
        $notifications = app(NotificationService::class);
        $notifications->preference($user)->update(['wechat_enabled' => true, 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '00:00']);
        $notifications->saveTemplate($admin, [
            'scene_code' => 'task_completed',
            'channel' => 'wechat_oa',
            'subject' => '任务状态更新',
            'body' => '{"thing1":"{{task_title}}","phrase2":"{{status_text}}"}',
            'allowed_variables' => ['task_title', 'status_text'],
            'provider_template_id' => 'OA_TASK_TEMPLATE',
            'publish' => true,
        ]);
        app(NotificationSceneConfigService::class)->configuration('task_completed')
            ->update(['channels' => ['in_app', 'wechat_oa']]);

        app(NotificationBusinessEventService::class)->record($user, 'task_completed', [
            'task_title' => '公众号任务提醒',
            'status_text' => '审核通过',
            'task_claim_id' => 'CLAIM-OA-1',
            'task_id' => 'TASK-OA-1',
            'occurred_at' => '2026-11-18 10:00',
        ], 'task:CLAIM-OA-1:completed', ['task_claim_id' => 'CLAIM-OA-1']);

        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'wechat_oa', 'status' => 'pending']);
        $this->assertSame(1, $notifications->deliverPending());
        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'wechat_oa',
            'status' => 'sent',
            'provider_message_id' => 'oa-message-001',
        ]);

        $sent = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.weixin.qq.com/cgi-bin/message/subscribe/bizsend'))->first();
        $this->assertNotNull($sent);
        /** @var Request $messageRequest */
        $messageRequest = $sent[0];
        $this->assertStringContainsString('access_token=oa-access-token', $messageRequest->url());
        $this->assertSame('oa-openid-001', $messageRequest['touser']);
        $this->assertSame('OA_TASK_TEMPLATE', $messageRequest['template_id']);
        $this->assertSame('https://rwsc.example.cn/#/pages/task-claim/index?id=CLAIM-OA-1', $messageRequest['url']);
        $this->assertSame('公众号任务提醒', $messageRequest['data']['thing1']['value']);
    }

    public function test_wechat_subscription_result_is_idempotent_and_rejection_keeps_existing_grant(): void
    {
        config()->set('wechat_auth.mini.app_id', 'wxsubscriptiontest001');
        $appIdHash = hash('sha256', 'wxsubscriptiontest001');
        $user = User::factory()->create(['status' => 'active']);
        $identity = UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'wechat_mini',
            'provider_user_id' => hash('sha256', 'mini-openid-grant'),
            'provider_user_value' => 'mini-openid-grant',
            'metadata' => ['app_id_hash' => $appIdHash],
        ]);
        $admin = AdminUser::query()->create(['account' => 'grant-template-owner', 'display_name' => '授权模板管理员', 'password' => 'AdminPassword12345!']);
        app(NotificationService::class)->saveTemplate($admin, [
            'scene_code' => 'task_completed',
            'channel' => 'wechat_mini',
            'subject' => '任务状态更新',
            'body' => '{"thing1":"{{task_title}}"}',
            'allowed_variables' => ['task_title'],
            'provider_template_id' => 'TASK_GRANT_TEMPLATE',
            'publish' => true,
        ]);
        $service = app(WechatSubscriptionGrantService::class);
        $accepted = [['template_id' => 'TASK_GRANT_TEMPLATE', 'status' => 'accept']];

        $service->record($identity, $accepted, 'wechat-request-idempotent-001');
        $service->record($identity, $accepted, 'wechat-request-idempotent-001');
        $service->record($identity, [['template_id' => 'TASK_GRANT_TEMPLATE', 'status' => 'reject']], 'wechat-request-reject-002');

        $this->assertDatabaseHas('wechat_subscription_grants', [
            'user_identity_id' => $identity->id,
            'provider_app_id_hash' => $appIdHash,
            'provider_template_id' => 'TASK_GRANT_TEMPLATE',
            'status' => 'accepted',
            'available_count' => 1,
        ]);
        $this->assertDatabaseCount('wechat_subscription_requests', 2);
    }

    public function test_business_event_stops_retrying_after_five_attempts(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $event = NotificationBusinessEvent::query()->create([
            'user_id' => $user->id,
            'scene_code' => 'task_claimed',
            'event_key_hash' => hash('sha256', 'exhausted-business-event'),
            'variables' => [],
            'data' => [],
            'status' => 'failed',
            'attempt_count' => 5,
        ]);
        $service = app(NotificationBusinessEventService::class);

        $this->assertSame(0, $service->processPending());
        $this->assertFalse($service->process($event->id));
        $this->assertDatabaseHas('notification_business_events', [
            'id' => $event->id,
            'status' => 'failed',
            'attempt_count' => 5,
        ]);
    }
}
