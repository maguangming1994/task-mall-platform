<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Robot\Models\RobotMessageRequest;
use App\Modules\Robot\Models\RobotXpCredential;
use App\Modules\Robot\Services\RobotMessageService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminRobotManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_robot_permissions_and_navigation_are_scoped(): void
    {
        [, $operationsToken] = $this->admin('operations_admin', 'robot-operations');
        [, $auditorToken] = $this->admin('auditor', 'robot-auditor');

        $navigation = $this->withToken($operationsToken)->getJson('/api/v1/admin/navigation')
            ->assertOk()->json('data');
        $robot = collect($navigation)->firstWhere('code', 'robot');
        $this->assertSame('/robot', $robot['path']);
        $this->withToken($operationsToken)->getJson('/api/v1/admin/robot/settings')->assertOk();
        // 运营管理员仅可查看与配置，不得直接向真实微信群发送消息。
        Http::fake();
        $this->withToken($operationsToken)->withHeader('Idempotency-Key', 'robot-operations-denied-001')
            ->postJson('/api/v1/admin/robot/messages/group-text', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom', 'message' => '运营不应发送',
            ])->assertForbidden();
        Http::assertNothingSent();

        $auditorNavigation = $this->withToken($auditorToken)->getJson('/api/v1/admin/navigation')
            ->assertOk()->json('data');
        $this->assertNull(collect($auditorNavigation)->firstWhere('code', 'robot'));
        $this->withToken($auditorToken)->getJson('/api/v1/admin/robot/settings')->assertForbidden();
        $this->withToken($auditorToken)->withHeader('Idempotency-Key', 'robot-denied-key-001')
            ->postJson('/api/v1/admin/robot/messages/group-text', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom', 'message' => '不应发送',
            ])->assertForbidden();
    }

    public function test_xp_api_key_requires_recent_verification_is_encrypted_hidden_and_audited(): void
    {
        [, $token] = $this->admin('operations_admin', 'robot-settings');
        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);
        $secret = 'xp-test-secret-key-123456';

        $this->withToken($token)->putJson('/api/v1/admin/robot/settings', [
            'api_key' => $secret, 'reason' => '配置 XP 机器人正式租户密钥。',
        ])->assertForbidden()->assertJsonPath('code', 'ADMIN_RECENT_VERIFICATION_REQUIRED');
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])->assertOk();

        $response = $this->withToken($token)->putJson('/api/v1/admin/robot/settings', [
            'api_key' => $secret, 'reason' => '配置 XP 机器人正式租户密钥。',
        ])->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.source', 'backend')
            ->assertJsonPath('data.base_url', 'https://xp.vvbot.cc');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, $this->withToken($token)->getJson('/api/v1/admin/robot/settings')->assertOk()->getContent());

        $credential = RobotXpCredential::query()->where('provider', 'xp')->firstOrFail();
        $raw = DB::table('robot_xp_credentials')->where('provider', 'xp')->firstOrFail();
        $this->assertSame($secret, $credential->api_key);
        $this->assertNotSame($secret, $raw->api_key);
        $log = DB::table('operation_logs')->where('action', 'robot.xp.settings.update')->firstOrFail();
        $this->assertStringNotContainsString($secret, (string) $log->before_value.(string) $log->after_value);

        Http::fake(['https://xp.vvbot.cc/api/tenant/devices*' => Http::response([
            'code' => 200, 'msg' => 'success', 'data' => ['devices' => [], 'total' => 0, 'online_count' => 0],
        ])]);
        $this->withToken($token)->postJson('/api/v1/admin/robot/settings/test')
            ->assertOk()->assertJsonPath('data.accepted', true);
        Http::assertSent(fn (Request $request): bool => $request->header('X-API-Key')[0] === $secret);
        $this->assertDatabaseHas('robot_xp_credentials', ['provider' => 'xp', 'last_test_status' => 'success']);
    }

    public function test_device_account_and_group_queries_are_proxied_with_expected_parameters(): void
    {
        [, $token] = $this->admin('operations_admin', 'robot-proxy');
        $secret = 'xp-proxy-secret-key';
        config()->set('services.xp.api_key', $secret);
        Http::fake([
            'https://xp.vvbot.cc/api/tenant/devices*' => Http::response(['code' => 200, 'data' => [
                'devices' => [['device_id' => 'device-001', 'online' => true]], 'total' => 1, 'online_count' => 1,
            ]]),
            'https://xp.vvbot.cc/api/wx/accounts*' => Http::response(['code' => 200, 'data' => [
                'accounts' => [['wxid' => 'wxid_owner', 'nickname' => '运营号']],
            ]]),
            'https://xp.vvbot.cc/api/wx/groups/realtime*' => Http::response(['code' => 200, 'data' => [
                'groups' => [['group_id' => 'group@chatroom', 'name' => '测试群']],
            ]]),
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/robot/devices?status=enabled')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.device_id', 'device-001');
        $this->withToken($token)->getJson('/api/v1/admin/robot/accounts?device_id=device-001')
            ->assertOk()->assertJsonPath('data.items.0.wxid', 'wxid_owner');
        $this->withToken($token)->getJson('/api/v1/admin/robot/groups?device_id=device-001&wxid=wxid_owner')
            ->assertOk()->assertJsonPath('data.items.0.group_id', 'group@chatroom');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/tenant/devices')
            && $request['status'] === 'enabled' && $request->header('X-API-Key')[0] === $secret);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/wx/accounts')
            && $request['device_id'] === 'device-001' && (int) $request['timeout'] === 30000);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/wx/groups/realtime')
            && $request['device_id'] === 'device-001' && $request['wxid'] === 'wxid_owner'
            && $request['force_realtime'] === 'false');
    }

    public function test_group_text_is_persisted_idempotent_and_refreshed_by_cmd_id(): void
    {
        // 发送类能力已收紧为 robot.message_send，operations_admin 不再持有，需用超管夹具验证业务链路。
        [, $token] = $this->admin('super_admin', 'robot-message');
        config()->set('services.xp.api_key', 'xp-message-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::response(['code' => 200, 'msg' => 'success', 'data' => [
                'cmd_id' => 'cmd-robot-001', 'status' => 'QUEUED', 'queued' => true,
                'resolved_device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
            ]]),
            'https://xp.vvbot.cc/api/msg/request/status*' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-robot-001', 'status' => 'SUCCESS', 'queued' => false, 'resolved_device_id' => 'device-001',
            ]]),
        ]);
        $payload = [
            'device_id' => 'device-001', 'wxid' => 'wxid_owner',
            'group_id' => 'group@chatroom', 'group_name' => '测试群', 'message' => '今晚八点开始。',
        ];

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-message-key-001')
            ->postJson('/api/v1/admin/robot/messages/group-text', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.group_name', '测试群')
            ->assertJsonPath('data.cmd_id', 'cmd-robot-001')->json('data');
        $this->assertNotSame('SUCCESS', $created['status']);
        $repeated = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-message-key-001')
            ->postJson('/api/v1/admin/robot/messages/group-text', $payload)
            ->assertCreated()->assertJsonPath('data.id', $created['id'])->json('data');
        $this->assertSame($created['cmd_id'], $repeated['cmd_id']);
        $this->assertDatabaseCount('robot_message_requests', 1);
        $this->assertDatabaseHas('robot_message_requests', ['group_id' => 'group@chatroom', 'group_name' => '测试群']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-group'
            && $request['msg_type'] === 'text' && $request['message'] === '今晚八点开始。'
            && (int) $request['timeout'] === 30000 && $request->header('X-API-Key')[0] === 'xp-message-secret');

        $this->withToken($token)->postJson('/api/v1/admin/robot/messages/'.$created['id'].'/refresh')
            ->assertOk()->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.provider_status', 'SUCCESS')->assertJsonPath('data.queued', false);
        $this->withToken($token)->getJson('/api/v1/admin/robot/messages')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.status', 'SUCCESS');
        $record = RobotMessageRequest::query()->firstOrFail();
        $this->assertNotNull($record->last_synced_at);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.message.group_text.submit', 'subject_id' => $created['id']]);
    }

    public function test_external_submission_failure_is_unknown_does_not_leak_key_and_is_not_retried(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-failure');
        $secret = 'xp-must-not-leak-secret';
        config()->set('services.xp.api_key', $secret);
        Http::fake(['https://xp.vvbot.cc/api/msg/send-group' => Http::response([
            'code' => 500, 'msg' => 'failed with '.$secret, 'data' => null,
        ], 500)]);
        $payload = [
            'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom', 'message' => '失败场景测试',
        ];

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-failure-key-001')
            ->postJson('/api/v1/admin/robot/messages/group-text', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'SUBMIT_UNKNOWN')
            ->assertJsonPath('data.error_code', 'XP_SUBMIT_UNKNOWN');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $id = $response->json('data.id');
        $this->withToken($token)->withHeader('Idempotency-Key', 'robot-failure-key-001')
            ->postJson('/api/v1/admin/robot/messages/group-text', $payload)
            ->assertCreated()->assertJsonPath('data.id', $id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('robot_message_requests', 1);
    }

    public function test_image_message_sends_public_url_with_image_type_and_longer_timeout(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-image');
        config()->set('services.xp.api_key', 'xp-image-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-image-001', 'status' => 'ACCEPTED', 'queued' => false,
            ]]),
        ]);
        $imageUrl = 'https://cdn.example.com/robot/poster.jpg';

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-image-key-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'group_name' => '图片群', 'msg_type' => 'image', 'message' => $imageUrl,
            ])->assertCreated()->assertJsonPath('data.status', 'ACCEPTED')
            ->assertJsonPath('data.msg_type', 'image')->assertJsonPath('data.message', $imageUrl)
            ->assertJsonPath('data.cmd_id', 'cmd-image-001')->json('data');

        $this->assertDatabaseHas('robot_message_requests', [
            'public_id' => $created['id'], 'msg_type' => 'image', 'message' => $imageUrl, 'status' => 'ACCEPTED',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-group'
            && $request['msg_type'] === 'image'
            && $request['message'] === $imageUrl
            && (int) $request['timeout'] === 60000
            && $request['device_id'] === 'device-001'
            && $request->header('X-API-Key')[0] === 'xp-image-secret');

        // 图片必须是公网 URL，非法值应在本地校验阶段被拒绝，不调用 XP。
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::response(['code' => 200, 'data' => ['cmd_id' => 'should-not-happen']]),
        ]);
        $this->withToken($token)->withHeader('Idempotency-Key', 'robot-image-bad-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'image', 'message' => 'not-a-url',
            ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['message']]]);
        Http::assertNothingSent();
    }

    public function test_link_card_message_posts_send_link_card_with_structured_payload(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-link');
        config()->set('services.xp.api_key', 'xp-link-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-link-card' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-link-001', 'status' => 'ACCEPTED', 'queued' => false,
            ]]),
        ]);

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-link-key-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'link',
                'link_url' => 'https://example.com/campaign', 'title' => '活动标题',
                'desc' => '活动描述', 'image_url' => 'https://cdn.example.com/cover.jpg',
            ])->assertCreated()->assertJsonPath('data.msg_type', 'link')
            ->assertJsonPath('data.payload.link_url', 'https://example.com/campaign')
            ->assertJsonPath('data.payload.title', '活动标题')
            ->assertJsonPath('data.payload.desc', '活动描述')
            ->assertJsonPath('data.payload.image_url', 'https://cdn.example.com/cover.jpg')
            ->assertJsonPath('data.cmd_id', 'cmd-link-001')->json('data');

        $this->assertDatabaseHas('robot_message_requests', ['public_id' => $created['id'], 'msg_type' => 'link']);
        $record = RobotMessageRequest::query()->where('public_id', $created['id'])->firstOrFail();
        $this->assertNull($record->message);
        $this->assertSame('https://example.com/campaign', $record->payload['link_url']);
        $this->assertSame('活动标题', $record->payload['title']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-link-card'
            && $request['link_url'] === 'https://example.com/campaign'
            && $request['title'] === '活动标题'
            && $request['desc'] === '活动描述'
            && $request['image_url'] === 'https://cdn.example.com/cover.jpg'
            && ! isset($request['msg_type'])
            && (int) $request['timeout'] === 30000);

        // 缺少必填标题应在本地校验阶段被拒绝，不调用 XP。
        $this->withToken($token)->withHeader('Idempotency-Key', 'robot-link-bad-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'link', 'link_url' => 'https://example.com/x',
            ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['title']]]);
        Http::assertSentCount(1);
    }

    public function test_scheduled_message_is_persisted_without_calling_xp(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-schedule');
        config()->set('services.xp.api_key', 'xp-schedule-secret');
        Http::fake(['https://xp.vvbot.cc/api/msg/*' => Http::response(['code' => 200, 'data' => []])]);
        $scheduledLocal = now()->addDay()->setTime(5, 5)->startOfSecond();
        $scheduledAt = $scheduledLocal->clone()->utc()->toIso8601String();

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-sched-key-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '定时文本',
                'scheduled_at' => $scheduledAt,
            ])->assertCreated()->assertJsonPath('data.status', 'SCHEDULED')
            ->assertJsonPath('data.msg_type', 'text')->json('data');

        $this->assertSame($scheduledLocal->toIso8601String(), $created['scheduled_at']);
        $this->assertNull($created['dispatched_at']);
        $this->assertNull($created['canceled_at']);
        $this->assertNull($created['cmd_id']);
        // 定时创建仅落库，到点前不得调用 XP。
        Http::assertSentCount(0);
        $this->assertDatabaseHas('robot_message_requests', [
            'public_id' => $created['id'], 'status' => 'SCHEDULED', 'msg_type' => 'text', 'message' => '定时文本',
        ]);
        $record = RobotMessageRequest::query()->where('public_id', $created['id'])->firstOrFail();
        $this->assertSame($scheduledLocal->toIso8601String(), $record->scheduled_at?->toIso8601String());
        $this->assertSame($scheduledLocal->format('Y-m-d H:i:s'), $record->getRawOriginal('scheduled_at'));
        $this->assertNull($record->dispatched_at);
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.message.schedule', 'subject_id' => $created['id']]);
    }

    public function test_scheduled_batch_preserves_utc_timestamp_and_replays_idempotently(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-schedule-timezone');
        config()->set('services.xp.api_key', 'xp-batch-schedule-timezone-secret');
        Http::fake();
        $scheduledLocal = now()->addDay()->setTime(5, 5)->startOfSecond();
        $payload = [
            'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
            'scheduled_at' => $scheduledLocal->clone()->utc()->toIso8601String(),
            'items' => [
                ['msg_type' => 'image', 'message' => 'https://cdn.example.com/batch/timezone.jpg'],
                ['msg_type' => 'text', 'message' => '定时组合文本'],
            ],
        ];

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-timezone-001')
            ->postJson('/api/v1/admin/robot/message-batches', $payload)
            ->assertCreated()->json('data');
        $replayed = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-timezone-001')
            ->postJson('/api/v1/admin/robot/message-batches', $payload)
            ->assertCreated()->assertJsonPath('data.batch_id', $created['batch_id'])->json('data');

        $this->assertSame(array_column($created['items'], 'id'), array_column($replayed['items'], 'id'));
        foreach ($created['items'] as $item) {
            $this->assertSame($scheduledLocal->toIso8601String(), $item['scheduled_at']);
            $record = RobotMessageRequest::query()->where('public_id', $item['id'])->firstOrFail();
            $this->assertSame($scheduledLocal->format('Y-m-d H:i:s'), $record->getRawOriginal('scheduled_at'));
        }
        $this->assertDatabaseCount('robot_message_requests', 2);
        Http::assertNothingSent();
    }

    public function test_dispatch_scheduled_command_dispatches_due_records_and_writes_dispatched_at(): void
    {
        [$admin] = $this->admin('operations_admin', 'robot-dispatch');
        config()->set('services.xp.api_key', 'xp-dispatch-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-dispatch-001', 'status' => 'SUCCESS', 'queued' => false,
                'resolved_device_id' => 'device-001',
            ]]),
        ]);

        $record = RobotMessageRequest::query()->create([
            'created_by_admin_id' => $admin->id,
            'idempotency_key_hash' => hash('sha256', 'robot-dispatch-direct-0001'),
            'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
            'group_name' => '定时派发群', 'msg_type' => 'text', 'message' => '到点自动发送',
            'status' => RobotMessageService::STATUS_SCHEDULED, 'queued' => false,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('robot:dispatch-scheduled')->assertSuccessful();

        $record->refresh();
        $this->assertSame('SUCCESS', $record->status);
        $this->assertSame('cmd-dispatch-001', $record->cmd_id);
        $this->assertNotNull($record->dispatched_at);
        $this->assertNotNull($record->submitted_at);
        $this->assertNull($record->canceled_at);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-group'
            && $request['msg_type'] === 'text' && $request['message'] === '到点自动发送');
    }

    public function test_scheduled_message_can_be_canceled_and_non_scheduled_cannot(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-cancel');
        config()->set('services.xp.api_key', 'xp-cancel-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-cancel-guard', 'status' => 'QUEUED', 'queued' => true,
            ]]),
        ]);

        $scheduled = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-cancel-sched-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '可取消的定时',
                'scheduled_at' => now()->addMinutes(20)->toIso8601String(),
            ])->assertCreated()->assertJsonPath('data.status', 'SCHEDULED')->json('data');

        $canceled = $this->withToken($token)
            ->postJson('/api/v1/admin/robot/messages/'.$scheduled['id'].'/cancel')
            ->assertOk()->assertJsonPath('data.status', 'CANCELED')->json('data');
        $this->assertNotNull($canceled['canceled_at']);
        $this->assertDatabaseHas('robot_message_requests', ['public_id' => $scheduled['id'], 'status' => 'CANCELED']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.message.cancel', 'subject_id' => $scheduled['id']]);

        // 已取消的计划不得被调度器派发。
        Http::assertSentCount(0);
        $this->artisan('robot:dispatch-scheduled')->assertSuccessful();
        Http::assertSentCount(0);

        // 重复取消（已非 SCHEDULED）应被拒绝。
        $this->withToken($token)->postJson('/api/v1/admin/robot/messages/'.$scheduled['id'].'/cancel')
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['status']]]);

        // 对已发送（非定时）消息取消也应被拒绝。
        $sent = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-cancel-sent-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '已发送不可取消',
            ])->assertCreated()->json('data');
        $this->withToken($token)->postJson('/api/v1/admin/robot/messages/'.$sent['id'].'/cancel')
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['status']]]);
    }

    public function test_mixed_message_batch_sends_in_sequence_and_audits_once(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-mixed');
        config()->set('services.xp.api_key', 'xp-batch-mixed-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::sequence()
                ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-batch-image', 'status' => 'QUEUED', 'queued' => true]])
                ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-batch-text', 'status' => 'ACCEPTED', 'queued' => false]]),
            'https://xp.vvbot.cc/api/msg/send-link-card' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-batch-link', 'status' => 'SUCCESS', 'queued' => false,
            ]]),
        ]);

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-mixed-0001')
            ->postJson('/api/v1/admin/robot/message-batches', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'group_name' => '组合测试群',
                'items' => [
                    ['msg_type' => 'image', 'message' => 'https://cdn.example.com/batch/poster.jpg'],
                    ['msg_type' => 'text', 'message' => '图片后的文字'],
                    ['msg_type' => 'link', 'link_url' => 'https://example.com/detail', 'title' => '组合链接'],
                ],
            ])->assertCreated()
            ->assertJsonPath('data.batch_size', 3)
            ->assertJsonPath('data.items.0.batch_sequence', 1)
            ->assertJsonPath('data.items.1.batch_sequence', 2)
            ->assertJsonPath('data.items.2.batch_sequence', 3)
            ->assertJsonPath('data.items.0.cmd_id', 'cmd-batch-image')
            ->assertJsonPath('data.items.1.cmd_id', 'cmd-batch-text')
            ->assertJsonPath('data.items.2.cmd_id', 'cmd-batch-link')->json('data');

        $this->assertSame([1, 2, 3], RobotMessageRequest::query()->where('batch_id', $created['batch_id'])
            ->orderBy('batch_sequence')->pluck('batch_sequence')->all());
        Http::assertSentInOrder([
            fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-group'
                && $request['msg_type'] === 'image' && (int) $request['timeout'] === 60000,
            fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-group'
                && $request['msg_type'] === 'text' && $request['message'] === '图片后的文字',
            fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-link-card'
                && $request['title'] === '组合链接',
        ]);
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'robot.message_batch.submit')->count());
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'robot.message_batch.submit', 'subject_id' => $created['batch_id'],
        ]);
    }

    public function test_message_batch_idempotency_replays_without_resending_and_rejects_changed_content(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-idempotent');
        config()->set('services.xp.api_key', 'xp-batch-idempotent-secret');
        Http::fake(['https://xp.vvbot.cc/api/msg/send-group' => Http::sequence()
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-batch-idem-1', 'status' => 'QUEUED']])
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-batch-idem-2', 'status' => 'QUEUED']]),
        ]);
        $payload = [
            'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
            'items' => [
                ['msg_type' => 'text', 'message' => '组合第一条'],
                ['msg_type' => 'text', 'message' => '组合第二条'],
            ],
        ];

        $first = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-idem-0001')
            ->postJson('/api/v1/admin/robot/message-batches', $payload)->assertCreated()->json('data');
        $replayed = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-idem-0001')
            ->postJson('/api/v1/admin/robot/message-batches', $payload)->assertCreated()
            ->assertJsonPath('data.batch_id', $first['batch_id'])->json('data');
        $this->assertSame(array_column($first['items'], 'id'), array_column($replayed['items'], 'id'));
        $this->assertDatabaseCount('robot_message_requests', 2);
        Http::assertSentCount(2);

        $payload['items'][1]['message'] = '相同键但内容已变化';
        $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-idem-0001')
            ->postJson('/api/v1/admin/robot/message-batches', $payload)
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['idempotency_key']]]);
        $this->assertDatabaseCount('robot_message_requests', 2);
        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'robot.message_batch.submit')->count());
    }

    public function test_invalid_message_batch_item_persists_and_sends_nothing(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-invalid');
        config()->set('services.xp.api_key', 'xp-batch-invalid-secret');
        Http::fake();

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-invalid-0001')
            ->postJson('/api/v1/admin/robot/message-batches', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'items' => [
                    ['msg_type' => 'text', 'message' => '合法内容'],
                    ['msg_type' => 'image', 'message' => 'not-a-public-url'],
                ],
            ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('items.1.message', $response->json('details.errors'));
        $this->assertDatabaseCount('robot_message_requests', 0);
        Http::assertNothingSent();
    }

    public function test_scheduled_message_batch_can_only_be_canceled_as_a_whole(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-cancel');
        config()->set('services.xp.api_key', 'xp-batch-cancel-secret');
        Http::fake();

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-cancel-0001')
            ->postJson('/api/v1/admin/robot/message-batches', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'scheduled_at' => now()->addMinutes(30)->toIso8601String(),
                'items' => [
                    ['msg_type' => 'image', 'message' => 'https://cdn.example.com/batch/scheduled.jpg'],
                    ['msg_type' => 'text', 'message' => '定时组合文本'],
                ],
            ])->assertCreated()->assertJsonPath('data.items.0.status', 'SCHEDULED')
            ->assertJsonPath('data.items.1.status', 'SCHEDULED')->json('data');
        Http::assertNothingSent();

        $this->withToken($token)->postJson('/api/v1/admin/robot/messages/'.$created['items'][0]['id'].'/cancel')
            ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['batch_id']]]);
        $this->withToken($token)->postJson('/api/v1/admin/robot/message-batches/'.$created['batch_id'].'/cancel')
            ->assertOk()->assertJsonPath('data.items.0.status', 'CANCELED')
            ->assertJsonPath('data.items.1.status', 'CANCELED');
        $this->assertSame(2, RobotMessageRequest::query()->where('batch_id', $created['batch_id'])
            ->where('status', 'CANCELED')->whereNotNull('canceled_at')->count());

        RobotMessageRequest::query()->where('batch_id', $created['batch_id'])->update(['scheduled_at' => now()->subMinute()]);
        $this->artisan('robot:dispatch-scheduled')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'robot.message_batch.cancel', 'subject_id' => $created['batch_id'],
        ]);
    }

    public function test_due_dispatch_limit_counts_whole_batches_and_does_not_interleave_groups(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-due');
        config()->set('services.xp.api_key', 'xp-batch-due-secret');
        Http::fake(['https://xp.vvbot.cc/api/msg/send-group' => Http::sequence()
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-due-a1', 'status' => 'QUEUED']])
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-due-a2', 'status' => 'QUEUED']])
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-due-b1', 'status' => 'QUEUED']])
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-due-b2', 'status' => 'QUEUED']]),
        ]);
        $createBatch = function (string $key, string $first, string $second) use ($token): array {
            return $this->withToken($token)->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/admin/robot/message-batches', [
                    'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                    'scheduled_at' => now()->addMinutes(20)->toIso8601String(),
                    'items' => [
                        ['msg_type' => 'text', 'message' => $first],
                        ['msg_type' => 'text', 'message' => $second],
                    ],
                ])->assertCreated()->json('data');
        };
        $batchA = $createBatch('robot-batch-due-a-0001', 'A1', 'A2');
        $batchB = $createBatch('robot-batch-due-b-0001', 'B1', 'B2');
        RobotMessageRequest::query()->whereIn('batch_id', [$batchA['batch_id'], $batchB['batch_id']])
            ->update(['scheduled_at' => now()->subMinute()]);

        $service = app(RobotMessageService::class);
        $this->assertSame(1, $service->dispatchDue(1));
        $this->assertSame(2, RobotMessageRequest::query()->where('batch_id', $batchA['batch_id'])->where('status', 'QUEUED')->count());
        $this->assertSame(2, RobotMessageRequest::query()->where('batch_id', $batchB['batch_id'])->where('status', 'SCHEDULED')->count());
        Http::assertSentCount(2);

        $this->assertSame(1, $service->dispatchDue(1));
        $this->assertSame(['A1', 'A2', 'B1', 'B2'], Http::recorded()
            ->map(fn (array $pair): string => $pair[0]['message'])->all());
    }

    public function test_message_batch_stops_after_unknown_result_and_skips_unsubmitted_items(): void
    {
        [, $token] = $this->admin('super_admin', 'robot-batch-stop');
        config()->set('services.xp.api_key', 'xp-batch-stop-secret');
        Http::fake(['https://xp.vvbot.cc/api/msg/send-group' => Http::sequence()
            ->push(['code' => 200, 'data' => ['cmd_id' => 'cmd-batch-stop-1', 'status' => 'QUEUED']])
            ->push(['code' => 500, 'msg' => 'XP temporarily unavailable', 'data' => null], 500),
        ]);

        $created = $this->withToken($token)->withHeader('Idempotency-Key', 'robot-batch-stop-0001')
            ->postJson('/api/v1/admin/robot/message-batches', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'items' => [
                    ['msg_type' => 'text', 'message' => '第一条成功'],
                    ['msg_type' => 'text', 'message' => '第二条结果未知'],
                    ['msg_type' => 'text', 'message' => '第三条必须跳过'],
                ],
            ])->assertCreated()->assertJsonPath('data.items.0.status', 'QUEUED')
            ->assertJsonPath('data.items.1.status', 'SUBMIT_UNKNOWN')
            ->assertJsonPath('data.items.2.status', 'SKIPPED')
            ->assertJsonPath('data.items.2.error_code', 'BATCH_PREVIOUS_FAILED')->json('data');

        Http::assertSentCount(2);
        $third = RobotMessageRequest::query()->where('public_id', $created['items'][2]['id'])->firstOrFail();
        $this->assertNull($third->cmd_id);
        $this->assertNull($third->submitted_at);
        $this->assertNull($third->dispatched_at);
    }

    public function test_message_list_backfills_batch_siblings_beyond_latest_100_boundary(): void
    {
        [$admin, $token] = $this->admin('operations_admin', 'robot-batch-list');
        $batchId = hash('sha256', 'robot-batch-list-boundary');
        foreach ([1, 2] as $sequence) {
            RobotMessageRequest::query()->create([
                'created_by_admin_id' => $admin->id,
                'idempotency_key_hash' => hash('sha256', 'robot-batch-list-item-'.$sequence),
                'batch_id' => $batchId, 'batch_sequence' => $sequence,
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '组合子项 '.$sequence,
                'status' => 'SCHEDULED', 'queued' => false, 'scheduled_at' => now()->addHour(),
            ]);
        }
        foreach (range(1, 99) as $index) {
            RobotMessageRequest::query()->create([
                'created_by_admin_id' => $admin->id,
                'idempotency_key_hash' => hash('sha256', 'robot-batch-list-single-'.$index),
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '后续单条 '.$index, 'status' => 'SUCCESS', 'queued' => false,
            ]);
        }

        $items = $this->withToken($token)->getJson('/api/v1/admin/robot/messages')
            ->assertOk()->assertJsonCount(101, 'data.items')->json('data.items');
        $this->assertSame([1, 2], collect($items)->where('batch_id', $batchId)
            ->pluck('batch_sequence')->sort()->values()->all());
    }

    public function test_stuck_message_batch_is_not_automatically_resent(): void
    {
        [$admin] = $this->admin('operations_admin', 'robot-batch-stuck');
        config()->set('services.xp.api_key', 'xp-batch-stuck-secret');
        Http::fake();
        $batchId = hash('sha256', 'robot-batch-stuck-recovery');
        foreach ([1, 2, 3] as $sequence) {
            RobotMessageRequest::query()->create([
                'created_by_admin_id' => $admin->id,
                'idempotency_key_hash' => hash('sha256', 'robot-batch-stuck-item-'.$sequence),
                'batch_id' => $batchId, 'batch_sequence' => $sequence,
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '卡死组合 '.$sequence,
                'cmd_id' => $sequence === 1 ? 'cmd-stuck-already-sent' : null,
                'status' => $sequence === 1 ? 'QUEUED' : 'DISPATCHING', 'queued' => $sequence === 1,
                'submitted_at' => $sequence === 1 ? now()->subMinutes(10) : null,
                'dispatched_at' => $sequence === 1 ? now()->subMinutes(10) : null,
                'scheduled_at' => now()->subMinute(),
            ]);
        }
        DB::table('robot_message_requests')->where('batch_id', $batchId)
            ->update(['updated_at' => now()->subMinutes(10)]);

        app(RobotMessageService::class)->dispatchDue(1);

        $records = RobotMessageRequest::query()->where('batch_id', $batchId)->orderBy('batch_sequence')->get();
        $this->assertSame('QUEUED', $records[0]->status);
        $this->assertSame('SUBMIT_UNKNOWN', $records[1]->status);
        $this->assertSame('BATCH_DISPATCH_INTERRUPTED', $records[1]->error_code);
        $this->assertSame('SKIPPED', $records[2]->status);
        $this->assertSame('BATCH_PREVIOUS_FAILED', $records[2]->error_code);
        Http::assertNothingSent();
    }

    public function test_message_send_permission_controls_send_cancel_and_upload_access(): void
    {
        // 先建一条真实的定时消息：无权限管理员取消时路由模型先命中，再被权限中间件拦截为 403（而非 404）。
        [, $opsToken] = $this->admin('super_admin', 'robot-perm-sender');
        config()->set('services.xp.api_key', 'xp-perm-secret');
        Http::fake(['https://xp.vvbot.cc/api/msg/*' => Http::response(['code' => 200, 'data' => []])]);
        $scheduled = $this->withToken($opsToken)->withHeader('Idempotency-Key', 'robot-perm-sched-0001')
            ->postJson('/api/v1/admin/robot/messages', [
                'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
                'msg_type' => 'text', 'message' => '权限校验目标',
                'scheduled_at' => now()->addMinutes(15)->toIso8601String(),
            ])->assertCreated()->json('data');

        $noPermAdmin = AdminUser::query()->create([
            'account' => 'robot-noperm', 'display_name' => '无权限', 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $noPermToken = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'robot-noperm', 'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        // 无 robot.message_send：发送、取消、上传均应被拒绝。
        $this->withToken($noPermToken)->postJson('/api/v1/admin/robot/messages', [
            'device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
            'msg_type' => 'text', 'message' => '不应发送',
        ])->assertForbidden();
        $this->withToken($noPermToken)->postJson('/api/v1/admin/robot/messages/'.$scheduled['id'].'/cancel')
            ->assertForbidden();
        $this->withToken($noPermToken)->post('/api/v1/admin/media/assets/upload', [
            'file' => UploadedFile::fake()->image('noperm.png', 10, 10),
        ], ['Accept' => 'application/json'])->assertForbidden();

        // 持 robot.message_send 的管理员可调用上传接口（白名单已包含该权限）。
        [, $token] = $this->admin('super_admin', 'robot-upload-perm');
        $this->withToken($token)->post('/api/v1/admin/media/assets/upload', [
            'file' => UploadedFile::fake()->image('robot-perm.png', 40, 40),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonStructure(['data' => ['url']]);
    }

    /** @return array{AdminUser, string} */
    private function admin(string $roleCode, string $account): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account, 'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
