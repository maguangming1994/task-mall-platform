<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Operations\Services\OperationsDashboardService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NotificationOperationsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_business_event_is_idempotent_in_app_first_quiet_hours_delay_external_and_essential_bypasses_delay(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $service = app(NotificationService::class);
        $preference = $service->preference($user);
        $preference->update(['wechat_enabled' => true, 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59']);
        $notification = $service->create($user, 'order', 'payment_succeeded', '支付成功', '订单已支付成功。', 'payment-success-order-001', false, ['wechat']);
        $same = $service->create($user, 'order', 'payment_succeeded', '重复消息', '不应覆盖。', 'payment-success-order-001', false, ['wechat']);
        $this->assertSame($notification->public_id, $same->public_id);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notification->id, 'channel' => 'in_app', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notification->id, 'channel' => 'wechat', 'status' => 'scheduled']);

        $essential = $service->create($user, 'after_sale', 'refund_succeeded', '退款成功', '退款已经到账。', 'refund-success-001', true, ['wechat']);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $essential->id, 'channel' => 'wechat', 'status' => 'pending']);
        $this->assertSame(1, $service->deliverPending());
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $essential->id, 'channel' => 'wechat', 'status' => 'sent']);
    }

    public function test_user_notification_list_is_scoped_filterable_paginated_and_returns_unread_summary_with_payload(): void
    {
        [$user, $token] = $this->loginAndConsent('13800002001');
        $other = User::factory()->create(['status' => 'active']);
        $service = app(NotificationService::class);
        $readTask = $service->create($user, 'task', 'task_reviewed', '任务审核完成', '第一条任务消息。', 'notification-list-task-read', false, ['in_app'], ['task_id' => 'task-public-read']);
        $readTask->update(['read_at' => now()]);
        $service->create($user, 'task', 'task_claim_expiring', '任务即将到期', '请尽快提交任务材料。', 'notification-list-task-unread', false, ['in_app'], ['task_claim_id' => 'claim-public-001']);
        $service->create($user, 'order', 'payment_succeeded', '订单支付成功', '订单已进入履约流程。', 'notification-list-order-unread', false, ['in_app'], ['order_id' => 'order-public-001']);
        $service->create($other, 'task', 'task_reviewed', '其他用户消息', '不应出现在当前用户列表。', 'notification-list-other-user');

        $this->withToken($token)->getJson('/api/v1/notifications?category=task&page=1&page_size=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.category', 'task')
            ->assertJsonPath('data.items.0.data.task_claim_id', 'claim-public-001')
            ->assertJsonPath('data.pagination.page', 1)
            ->assertJsonPath('data.pagination.page_size', 1)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.has_more', true)
            ->assertJsonPath('data.unread_total', 2)
            ->assertJsonPath('data.category_unread_counts.task', 1)
            ->assertJsonPath('data.category_unread_counts.order', 1)
            ->assertJsonPath('data.category_unread_counts.asset', 0);

        $this->withToken($token)->getJson('/api/v1/notifications?unread_only=1&page_size=100')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);

        $preference = $this->withToken($token)->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_start', '22:00')
            ->assertJsonPath('data.quiet_hours_end', '08:00')
            ->json('data');
        $this->withToken($token)->putJson('/api/v1/notification-preferences', [
            ...$preference,
            'quiet_hours_start' => '21:30',
            'quiet_hours_end' => '07:15',
        ])->assertOk()
            ->assertJsonPath('data.quiet_hours_start', '21:30')
            ->assertJsonPath('data.quiet_hours_end', '07:15');
    }

    public function test_mark_all_read_is_scoped_to_authenticated_user_and_optionally_to_category(): void
    {
        [$user, $token] = $this->loginAndConsent('13800002002');
        $other = User::factory()->create(['status' => 'active']);
        $service = app(NotificationService::class);
        $firstTask = $service->create($user, 'task', 'task_reviewed', '任务一', '任务消息一。', 'notification-read-all-task-1');
        $secondTask = $service->create($user, 'task', 'task_reviewed', '任务二', '任务消息二。', 'notification-read-all-task-2');
        $order = $service->create($user, 'order', 'payment_succeeded', '订单消息', '订单消息。', 'notification-read-all-order');
        $otherNotification = $service->create($other, 'task', 'task_reviewed', '其他用户任务', '不可被当前用户修改。', 'notification-read-all-other');

        $this->withHeader('Authorization', '')->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/notifications/read-all', ['category' => 'task'])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.unread_total', 1);
        $this->assertNotNull($firstTask->fresh()->read_at);
        $this->assertNotNull($secondTask->fresh()->read_at);
        $this->assertNull($order->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);

        $this->withToken($token)->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.unread_total', 0);
        $this->withToken($token)->postJson('/api/v1/notifications/'.$otherNotification->public_id.'/read')->assertNotFound();
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_marketing_campaign_only_targets_subscribers_and_template_variables_are_whitelisted(): void
    {
        $subscribed = User::factory()->create(['status' => 'active']);
        $unsubscribed = User::factory()->create(['status' => 'active']);
        $admin = AdminUser::query()->create(['account' => 'operations-admin', 'display_name' => '运营管理员', 'password' => 'AdminPassword12345!']);
        $service = app(NotificationService::class);
        $service->preference($subscribed)->update(['marketing_subscribed' => true]);
        $service->preference($unsubscribed)->update(['marketing_subscribed' => false]);
        $template = $service->saveTemplate($admin, ['scene_code' => 'campaign_launch', 'channel' => 'in_app', 'subject' => '{{nickname}}，新活动上线', 'body' => '活动 {{campaign_name}} 已开放。', 'allowed_variables' => ['nickname', 'campaign_name'], 'publish' => true]);
        $this->assertSame('published', $template->status);
        $this->assertSame(1, $template->current_version_no);
        try {
            $service->saveTemplate($admin, ['scene_code' => 'invalid_template', 'channel' => 'wechat', 'subject' => '{{secret}}', 'body' => '非法变量。', 'allowed_variables' => ['nickname'], 'publish' => false]);
            $this->fail('Expected non-whitelisted variable rejection.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('notification_templates', 1);
        }

        $campaign = $service->createCampaign($admin, ['name' => '八月成长活动', 'title' => '成长活动上线', 'body' => '完成任务赢积分。', 'channels' => ['in_app']], 'notification-campaign-001');
        $same = $service->createCampaign($admin, ['name' => '重复活动', 'title' => '不覆盖', 'body' => '不覆盖。', 'channels' => ['in_app']], 'notification-campaign-001');
        $this->assertSame($campaign->public_id, $same->public_id);
        $this->assertSame(1, $service->runCampaigns());
        $this->assertDatabaseHas('notification_campaigns', ['id' => $campaign->id, 'status' => 'completed', 'recipient_count' => 1, 'sent_count' => 1]);
        $this->assertDatabaseHas('notifications', ['user_id' => $subscribed->id, 'scene_code' => 'marketing_campaign']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $unsubscribed->id, 'scene_code' => 'marketing_campaign']);
        $metrics = app(OperationsDashboardService::class)->metrics();
        $this->assertArrayHasKey('notifications', $metrics);
        $this->assertArrayHasKey('orders', $metrics);
        $this->assertArrayHasKey('community', $metrics);
    }

    /** @return array{0: User, 1: string} */
    private function loginAndConsent(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'notifications-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'notification-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'],
                'action' => 'accept',
                'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }
}
