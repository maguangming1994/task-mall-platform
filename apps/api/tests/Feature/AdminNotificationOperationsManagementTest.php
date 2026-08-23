<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Notification\Services\NotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminNotificationOperationsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_operations_admin_can_view_template_history_and_publish_a_new_immutable_version_with_audit(): void
    {
        [$admin, $token] = $this->admin('operations_admin', 'notification-template-admin');

        $created = $this->withToken($token)->postJson('/api/v1/admin/notification-templates', [
            'scene_code' => 'campaign_launch',
            'channel' => 'in_app',
            'subject' => '{{nickname}}，活动上线',
            'body' => '活动 {{campaign_name}} 已开放。',
            'allowed_variables' => ['nickname', 'campaign_name'],
            'provider_template_id' => null,
            'publish' => true,
        ])->assertCreated()
            ->assertJsonPath('data.current_version_no', 1)
            ->assertJsonPath('data.current_version.subject', '{{nickname}}，活动上线')
            ->json('data');

        $this->withToken($token)->postJson('/api/v1/admin/notification-templates', [
            'scene_code' => 'campaign_launch',
            'channel' => 'in_app',
            'subject' => '{{nickname}}，活动提醒',
            'body' => '活动 {{campaign_name}} 正在进行。',
            'allowed_variables' => ['nickname', 'campaign_name'],
            'provider_template_id' => null,
            'publish' => true,
        ])->assertCreated()
            ->assertJsonPath('data.current_version_no', 2)
            ->assertJsonPath('data.current_version.subject', '{{nickname}}，活动提醒');

        $this->withToken($token)->getJson('/api/v1/admin/notification-templates/'.$created['id'])
            ->assertOk()
            ->assertJsonCount(2, 'data.versions')
            ->assertJsonPath('data.versions.0.version_no', 2)
            ->assertJsonPath('data.versions.1.version_no', 1);
        $this->assertDatabaseCount('notification_templates', 1);
        $this->assertDatabaseCount('notification_template_versions', 2);
        $this->assertDatabaseHas('operation_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'notification_template.version.publish',
            'subject_id' => $created['id'],
        ]);
    }

    public function test_campaign_cancel_and_failed_delivery_retry_enforce_state_machine_are_idempotent_and_audited(): void
    {
        [$admin, $token] = $this->admin('operations_admin', 'notification-campaign-admin');
        $scheduled = $this->withToken($token)->withHeader('Idempotency-Key', 'campaign-management-test-001')->postJson('/api/v1/admin/notification-campaigns', [
            'name' => '计划触达',
            'title' => '活动提醒',
            'body' => '今晚八点开始。',
            'channels' => ['in_app'],
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');

        $this->withToken($token)->postJson('/api/v1/admin/notification-campaigns/'.$scheduled['id'].'/cancel', [
            'reason' => '活动排期发生变化，取消本次触达。',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withToken($token)->postJson('/api/v1/admin/notification-campaigns/'.$scheduled['id'].'/cancel', [
            'reason' => '重复请求保持已取消状态。',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $user = User::factory()->create(['status' => 'active']);
        $notification = app(NotificationService::class)->create($user, 'system', 'campaign_launch', '失败消息', '等待重新投递。', 'failed-delivery-for-admin-test', false, ['wechat']);
        $deliveryId = DB::table('notification_deliveries')->where('notification_id', $notification->id)->where('channel', 'wechat')->value('id');
        DB::table('notification_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'retry_count' => 3, 'error_code' => 'ProviderError', 'error_message' => '渠道暂不可用']);

        $this->withToken($token)->getJson('/api/v1/admin/notification-deliveries')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $deliveryId)
            ->assertJsonPath('data.items.0.title', '失败消息');
        $this->withToken($token)->postJson('/api/v1/admin/notification-deliveries/'.$deliveryId.'/retry', [
            'reason' => '运营人员确认渠道恢复后重新投递。',
        ])->assertOk()->assertJsonPath('data.status', 'retrying')->assertJsonPath('data.retry_count', 0);
        $this->withToken($token)->postJson('/api/v1/admin/notification-deliveries/'.$deliveryId.'/retry', [
            'reason' => '重复请求保持在重试队列。',
        ])->assertOk()->assertJsonPath('data.status', 'retrying');

        $this->assertDatabaseHas('operation_logs', ['admin_user_id' => $admin->id, 'action' => 'notification_campaign.cancel', 'subject_id' => $scheduled['id']]);
        $this->assertDatabaseHas('operation_logs', ['admin_user_id' => $admin->id, 'action' => 'notification_delivery.retry', 'subject_id' => (string) $deliveryId]);
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'notification_campaign.cancel')->where('subject_id', $scheduled['id'])->count());
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'notification_delivery.retry')->where('subject_id', (string) $deliveryId)->count());
    }

    public function test_completed_campaign_and_sent_delivery_cannot_be_mutated_and_permission_is_required(): void
    {
        [, $operationsToken] = $this->admin('operations_admin', 'notification-state-admin');
        [, $auditorToken] = $this->admin('auditor', 'notification-readonly-auditor');
        $campaign = $this->withToken($operationsToken)->withHeader('Idempotency-Key', 'campaign-management-test-002')->postJson('/api/v1/admin/notification-campaigns', [
            'name' => '立即触达', 'title' => '立即发送', 'body' => '该消息立即进入发送队列。', 'channels' => ['in_app'],
        ])->assertCreated()->json('data');
        DB::table('notification_campaigns')->where('public_id', $campaign['id'])->update(['status' => 'completed', 'completed_at' => now()]);

        $this->withToken($operationsToken)->postJson('/api/v1/admin/notification-campaigns/'.$campaign['id'].'/cancel', ['reason' => '尝试取消已经完成的触达。'])
            ->assertUnprocessable()->assertJsonPath('details.errors.status.0', '只有草稿、待发送或计划中的触达可以取消。');

        $user = User::factory()->create(['status' => 'active']);
        $notification = app(NotificationService::class)->create($user, 'system', 'campaign_launch', '已发送消息', '无需重试。', 'sent-delivery-for-admin-test');
        $deliveryId = DB::table('notification_deliveries')->where('notification_id', $notification->id)->value('id');
        $this->withToken($operationsToken)->postJson('/api/v1/admin/notification-deliveries/'.$deliveryId.'/retry', ['reason' => '尝试重试已发送的消息。'])
            ->assertUnprocessable()->assertJsonPath('details.errors.status.0', '只有发送失败的通知可以重新投递。');

        $this->withToken($auditorToken)->getJson('/api/v1/admin/notification-templates')->assertForbidden();
        $this->withToken($auditorToken)->postJson('/api/v1/admin/notification-deliveries/'.$deliveryId.'/retry', ['reason' => '无权限操作不应成功。'])->assertForbidden();
    }

    /** @return array{AdminUser, string} */
    private function admin(string $roleCode, string $account): array
    {
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
