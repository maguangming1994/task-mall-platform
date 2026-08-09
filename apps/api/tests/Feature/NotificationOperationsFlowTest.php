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
}
