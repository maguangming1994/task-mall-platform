<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Robot\Models\RobotAssistant;
use App\Modules\Robot\Models\RobotForwardConfig;
use App\Modules\Robot\Services\RobotForwardConfigService;
use App\Modules\Robot\Services\RobotForwardContentService;
use App\Modules\Robot\Services\WechatMiniUrlLinkService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminRobotConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_forward_crud_filters_batch_status_validation_and_soft_delete(): void
    {
        [, $token] = $this->admin('operations_admin', 'forward-admin');
        [, $auditorToken] = $this->admin('auditor', 'forward-auditor');
        $this->withToken($auditorToken)->getJson('/api/v1/admin/robot/forwards')->assertForbidden();

        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);
        $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $this->forwardPayload())
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_RECENT_VERIFICATION_REQUIRED');
        $this->verify($token);

        $invalid = $this->forwardPayload();
        $invalid['filter_rules']['min_messages'] = 10;
        $invalid['filter_rules']['max_messages'] = 2;
        $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $invalid)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.filter_rules.0', '最少消息数不能大于最多消息数。');

        $created = $this->createForward($token);
        $this->assertSame('晚间转发', $created['name']);
        $this->assertSame('机器人测试大学', $created['filter_rules']['campus_name']);
        $this->assertSame('guide', $created['filter_rules']['categories'][0]['code']);
        $this->assertSame('实用攻略', $created['filter_rules']['categories'][0]['name']);
        $this->assertCount(1, $created['robot_targets'][0]['groups']);
        $this->assertSame(0, $created['stats']['test_count']);
        $this->assertSame(0, $created['stats']['success_count']);

        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards?name='.urlencode('晚间').'&enabled=1')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $created['id']);

        $updatedPayload = $this->forwardPayload('已编辑转发');
        $updatedPayload['description'] = '保留设备、账号和群名称快照。';
        $updatedPayload['robot_targets'][0]['target_id'] = $created['robot_targets'][0]['target_id'];
        $updated = $this->withToken($token)->putJson('/api/v1/admin/robot/forwards/'.$created['id'], $updatedPayload)
            ->assertOk()->assertJsonPath('data.name', '已编辑转发')
            ->assertJsonPath('data.robot_targets.0.target_id', $created['robot_targets'][0]['target_id'])
            ->json('data');
        $this->assertSame('iPhone 快照', $updated['robot_targets'][0]['device_name']);

        $second = $this->createForward($token, '二号转发');
        $this->withToken($token)->patchJson('/api/v1/admin/robot/forwards/'.$created['id'].'/status', ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->withToken($token)->postJson('/api/v1/admin/robot/forwards/batch-status', [
            'ids' => [$created['id'], $second['id']], 'enabled' => true,
        ])->assertOk()->assertJsonPath('data.items.0.enabled', true)->assertJsonPath('data.items.1.enabled', true);

        $this->withToken($token)->deleteJson('/api/v1/admin/robot/forwards/'.$second['id'])->assertOk();
        $this->assertSoftDeleted('robot_forward_configs', ['public_id' => $second['id']]);
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/'.$second['id'])->assertNotFound();
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.forward.create', 'subject_id' => $created['id']]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.forward.update', 'subject_id' => $created['id']]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.forward.batch_status']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'robot.forward.delete', 'subject_id' => $second['id']]);
    }

    public function test_forward_filter_options_validate_school_and_forum_categories(): void
    {
        [, $token] = $this->admin('operations_admin', 'forward-option-admin');
        [, $auditorToken] = $this->admin('auditor', 'forward-option-auditor');
        $this->forwardPayload();
        $campus = Campus::query()->where('code', 'robot-forward-campus')->firstOrFail();

        $this->withToken($auditorToken)->getJson('/api/v1/admin/robot/forwards/filter-options')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/filter-options')
            ->assertOk()->assertJsonCount(0, 'data.categories')
            ->assertJsonFragment(['id' => $campus->public_id, 'name' => '机器人测试大学']);
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/filter-options?campus_id='.$campus->public_id)
            ->assertOk()->assertJsonCount(4, 'data.categories')
            ->assertJsonFragment(['code' => 'guide', 'name' => '实用攻略']);
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/filter-options?campus_id=not-exists')
            ->assertUnprocessable();

        $this->verify($token);
        $invalidCategory = $this->forwardPayload();
        $invalidCategory['filter_rules']['categories'][0]['code'] = 'not-a-forum-category';
        $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $invalidCategory)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $withoutSchool = $this->forwardPayload();
        $withoutSchool['filter_rules']['campus_id'] = null;
        $withoutSchoolResponse = $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $withoutSchool)
            ->assertUnprocessable();
        $this->assertSame('请先选择学校，再选择该学校论坛的分类。', $withoutSchoolResponse->json('details.errors')['filter_rules.categories'][0]);
    }

    public function test_assistant_secret_binding_webhook_self_test_and_audit_are_safe(): void
    {
        [, $token] = $this->admin('operations_admin', 'assistant-admin');
        $this->verify($token);
        Http::fake();

        $response = $this->withToken($token)->postJson('/api/v1/admin/robot/assistants', [
            'name' => '内容助手', 'remark' => '用于群消息配置。', 'enabled' => true,
        ])->assertCreated()->assertJsonPath('data.robot_count', 0);
        $created = $response->json('data');
        $secret = $created['app_secret_once'];
        $this->assertSame(64, strlen($secret));
        $this->assertSame($secret, RobotAssistant::query()->where('public_id', $created['id'])->firstOrFail()->app_secret);
        $rawSecret = DB::table('robot_assistants')->where('public_id', $created['id'])->value('app_secret');
        $this->assertNotSame($secret, $rawSecret);
        $this->assertStringNotContainsString($secret, (string) $rawSecret);
        $this->assertNotSame($secret, $created['app_secret_masked']);

        $list = $this->withToken($token)->getJson('/api/v1/admin/robot/assistants?app_id='.urlencode($created['app_id']))
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonMissingPath('data.items.0.app_secret_once');
        $show = $this->withToken($token)->getJson('/api/v1/admin/robot/assistants/'.$created['id'])
            ->assertOk()->assertJsonMissingPath('data.app_secret_once');
        $updated = $this->withToken($token)->putJson('/api/v1/admin/robot/assistants/'.$created['id'], [
            'name' => '内容助手已编辑', 'remark' => '只更新基础资料。',
        ])->assertOk()->assertJsonPath('data.name', '内容助手已编辑')->assertJsonMissingPath('data.app_secret_once');
        $failedTest = $this->withToken($token)->postJson('/api/v1/admin/robot/assistants/'.$created['id'].'/webhook/test')
            ->assertOk()->assertJsonPath('data.last_test_status', 'LOCAL_FAILED');

        $bindingPayload = [
            'device_id' => 'device-001', 'device_name' => '测试设备',
            'wxid' => 'wxid_owner', 'account_name' => '运营微信',
        ];
        $bound = $this->withToken($token)->postJson('/api/v1/admin/robot/assistants/'.$created['id'].'/bindings', $bindingPayload)
            ->assertCreated()->assertJsonPath('data.robot_count', 1)
            ->assertJsonPath('data.robot_bindings.0.device_name', '测试设备');
        $bindingId = $bound->json('data.robot_bindings.0.binding_id');
        $this->withToken($token)->postJson('/api/v1/admin/robot/assistants/'.$created['id'].'/bindings', $bindingPayload)
            ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.wxid.0', '该机器人已绑定到当前助手，请勿重复绑定。');

        $webhook = $this->withToken($token)->getJson('/api/v1/admin/robot/assistants/'.$created['id'].'/webhook')
            ->assertOk()->assertJsonPath('data.binding_count', 1)->assertJsonPath('data.app_id', $created['app_id']);
        $this->assertStringEndsWith('/api/v1/robot/webhook/'.$created['id'], $webhook->json('data.url'));
        $readyTest = $this->withToken($token)->postJson('/api/v1/admin/robot/assistants/'.$created['id'].'/webhook/test')
            ->assertOk()->assertJsonPath('data.last_test_status', 'LOCAL_READY');

        $this->withToken($token)->deleteJson('/api/v1/admin/robot/assistants/'.$created['id'].'/bindings/'.$bindingId)
            ->assertOk()->assertJsonPath('data.robot_count', 0)->assertJsonCount(0, 'data.robot_bindings');
        $this->withToken($token)->patchJson('/api/v1/admin/robot/assistants/'.$created['id'].'/status', ['enabled' => false])
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->withToken($token)->deleteJson('/api/v1/admin/robot/assistants/'.$created['id'])->assertOk();
        $this->assertSoftDeleted('robot_assistants', ['public_id' => $created['id']]);

        foreach ([$list, $show, $updated, $failedTest, $bound, $webhook, $readyTest] as $safeResponse) {
            $this->assertStringNotContainsString($secret, $safeResponse->getContent());
        }
        $auditValues = DB::table('operation_logs')->where('action', 'like', 'robot.assistant.%')
            ->get(['before_value', 'after_value']);
        $this->assertNotEmpty($auditValues);
        foreach ($auditValues as $log) {
            $this->assertStringNotContainsString($secret, (string) $log->before_value.(string) $log->after_value);
        }
        Http::assertNothingSent();
    }

    public function test_forward_test_uses_saved_target_whitelist_template_idempotency_and_success_stats(): void
    {
        // 转发测试会真实发送群消息，需 robot.message_send；operations_admin 已不再持有该权限。
        [, $token] = $this->admin('super_admin', 'forward-test-admin');
        $this->verify($token);
        $template = '${index}:${title}:${content}:${total}:{{ 7 * 7 }}:${unknown}';
        $payload = $this->forwardPayload('模板测试', $template);
        $payload['template_config']['content_max_length'] = 8;
        $config = $this->createForwardFromPayload($token, $payload);
        $target = $config['robot_targets'][0];
        $group = $target['groups'][0];
        [, $noSendToken] = $this->adminWithPermissions('forward-no-send', ['robot.view', 'robot.settings_manage']);

        $this->withToken($noSendToken)->withHeader('Idempotency-Key', 'forward-no-send-key-001')
            ->postJson('/api/v1/admin/robot/forwards/'.$config['id'].'/test', [
                'target_id' => $target['target_id'], 'group_id' => $group['group_id'],
            ])->assertForbidden();

        config()->set('services.xp.api_key', 'xp-forward-test-secret');
        Http::fake([
            'https://xp.vvbot.cc/api/msg/send-group' => Http::response(['code' => 200, 'msg' => 'success', 'data' => [
                'cmd_id' => 'cmd-forward-001', 'status' => 'QUEUED', 'queued' => true,
                'resolved_device_id' => 'device-001', 'wxid' => 'wxid_owner', 'group_id' => 'group@chatroom',
            ]]),
            'https://xp.vvbot.cc/api/msg/request/status*' => Http::response(['code' => 200, 'data' => [
                'cmd_id' => 'cmd-forward-001', 'status' => 'SUCCESS', 'queued' => false,
                'resolved_device_id' => 'device-001',
            ]]),
        ]);

        $this->withToken($token)->withHeader('Idempotency-Key', 'forward-invalid-key-001')
            ->postJson('/api/v1/admin/robot/forwards/'.$config['id'].'/test', [
                'target_id' => $target['target_id'], 'group_id' => 'not-owned@chatroom',
            ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('details.errors.group_id.0', '目标群不属于所选机器人目标。');

        $requestData = ['target_id' => $target['target_id'], 'group_id' => $group['group_id']];
        $message = $this->withToken($token)->withHeader('Idempotency-Key', 'forward-test-key-0001')
            ->postJson('/api/v1/admin/robot/forwards/'.$config['id'].'/test', $requestData)
            ->assertCreated()->assertJsonPath('data.status', 'QUEUED')
            ->assertJsonPath('data.forward_config_id', $config['id'])
            ->assertJsonPath('data.trigger_type', 'CONFIG_TEST')
            ->assertJsonPath('data.cmd_id', 'cmd-forward-001')->json('data');
        $expectedContent = mb_substr('这是一条来自转发配置的测试消息，用于验证模板和机器人目标。', 0, 8);
        $expectedMessage = '1:XP 转发配置测试:'.$expectedContent.':1:{{ 7 * 7 }}:${unknown}';
        $this->assertSame($expectedMessage, $message['message']);

        $this->withToken($token)->withHeader('Idempotency-Key', 'forward-test-key-0001')
            ->postJson('/api/v1/admin/robot/forwards/'.$config['id'].'/test', $requestData)
            ->assertCreated()->assertJsonPath('data.id', $message['id']);
        $this->assertDatabaseCount('robot_message_requests', 1);
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/'.$config['id'])
            ->assertOk()->assertJsonPath('data.stats.test_count', 1)->assertJsonPath('data.stats.success_count', 0);

        $this->withToken($token)->postJson('/api/v1/admin/robot/messages/'.$message['id'].'/refresh')
            ->assertOk()->assertJsonPath('data.status', 'SUCCESS');
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/'.$config['id'])
            ->assertOk()->assertJsonPath('data.stats.test_count', 1)->assertJsonPath('data.stats.success_count', 1)
            ->assertJsonPath('data.stats.last_tested_at', $message['submitted_at']);

        $configModel = RobotForwardConfig::query()->where('public_id', $config['id'])->firstOrFail();
        $this->assertDatabaseHas('robot_message_requests', [
            'public_id' => $message['id'], 'forward_config_id' => $configModel->id, 'trigger_type' => 'CONFIG_TEST',
        ]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://xp.vvbot.cc/api/msg/send-group'
            && $request['message'] === $expectedMessage && $request['group_id'] === 'group@chatroom');
        $this->assertSame(1, DB::table('operation_logs')->where('action', 'robot.forward.test')->count());
    }

    public function test_forward_test_submission_failure_is_unknown_and_retry_does_not_resend(): void
    {
        [, $token] = $this->admin('super_admin', 'forward-failure-admin');
        $this->verify($token);
        $config = $this->createForward($token, '失败场景');
        $target = $config['robot_targets'][0];
        $secret = 'xp-forward-must-not-leak';
        config()->set('services.xp.api_key', $secret);
        Http::fake(['https://xp.vvbot.cc/api/msg/send-group' => Http::response([
            'code' => 500, 'msg' => 'failed with '.$secret, 'data' => null,
        ], 500)]);
        $data = ['target_id' => $target['target_id'], 'group_id' => $target['groups'][0]['group_id']];

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'forward-failure-key-001')
            ->postJson('/api/v1/admin/robot/forwards/'.$config['id'].'/test', $data)
            ->assertCreated()->assertJsonPath('data.status', 'SUBMIT_UNKNOWN')
            ->assertJsonPath('data.error_code', 'XP_SUBMIT_UNKNOWN');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $id = $response->json('data.id');
        $this->withToken($token)->withHeader('Idempotency-Key', 'forward-failure-key-001')
            ->postJson('/api/v1/admin/robot/forwards/'.$config['id'].'/test', $data)
            ->assertCreated()->assertJsonPath('data.id', $id);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('robot_message_requests', 1);
        $this->withToken($token)->getJson('/api/v1/admin/robot/forwards/'.$config['id'])
            ->assertOk()->assertJsonPath('data.stats.test_count', 1)->assertJsonPath('data.stats.success_count', 0);
    }

    public function test_forward_template_variables_map_to_community_post_fields(): void
    {
        config()->set('wechat_auth.driver', 'local');
        config()->set('app.user_h5_url', 'https://h5.example.com');
        $post = $this->communityPost();

        $variables = app(RobotForwardContentService::class)->variables($post, 2, 5, 6);

        $this->assertSame('2', $variables['${index}']);
        $this->assertSame('转发测试帖子', $variables['${title}']);
        $this->assertSame(mb_substr('这是一条用于验证模板变量的正文内容', 0, 6), $variables['${content}']);
        $this->assertSame('实用攻略', $variables['${categoryName}']);
        $this->assertSame($post->campus_id === null ? '校园' : Campus::query()->find($post->campus_id)?->name, $variables['${campus}']);
        $this->assertSame('发帖同学', $variables['${nickname}']);
        $this->assertSame('138****1234', $variables['${mobile}']);
        $this->assertSame('7', $variables['${likeNum}']);
        $this->assertSame('3', $variables['${commentNum}']);
        $this->assertSame('2', $variables['${favoriteNum}']);
        $this->assertSame('5', $variables['${total}']);
        $this->assertSame($post->published_at->format('Y-m-d H:i:s'), $variables['${addTime}']);
        $wappLink = $variables['${wappLink}'];
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(rtrim((string) config('app.url'), '/'), '#').'/s/[a-z2-9]{6}$#',
            $wappLink
        );
        $this->get('/s/'.substr($wappLink, strrpos($wappLink, '/') + 1))
            ->assertRedirect('https://h5.example.com/#/pages/community-detail/index?id='.$post->public_id);
        $this->assertSame('0', $variables['${lookNum}']);
        $this->assertSame('0', $variables['${shareNum}']);
    }

    public function test_wechat_urllink_is_generated_for_community_post(): void
    {
        $post = $this->communityPost();
        $this->configureWechatMini();

        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'token-1', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/wxa/generate_urllink*' => Http::response(['errcode' => 0, 'url_link' => 'mp://robotPostLink']),
        ]);

        $this->assertSame('mp://robotPostLink', app(WechatMiniUrlLinkService::class)->forCommunityPost($post));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.weixin.qq.com/wxa/generate_urllink')
            && $request['path'] === 'pages/community-detail/index'
            && $request['query'] === 'id='.$post->public_id);
    }

    public function test_wechat_urllink_falls_back_to_h5_link_when_wechat_rejects(): void
    {
        $post = $this->communityPost();
        config()->set('app.user_h5_url', 'https://h5.example.com');
        $this->configureWechatMini();

        Http::fake([
            'https://api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'token-2', 'expires_in' => 7200]),
            'https://api.weixin.qq.com/wxa/generate_urllink*' => Http::response(['errcode' => 40002, 'errmsg' => 'invalid']),
        ]);

        $wappLink = app(WechatMiniUrlLinkService::class)->forCommunityPost($post);
        $this->assertMatchesRegularExpression(
            '#^'.preg_quote(rtrim((string) config('app.url'), '/'), '#').'/s/[a-z2-9]{6}$#',
            $wappLink
        );
        $this->get('/s/'.substr($wappLink, strrpos($wappLink, '/') + 1))
            ->assertRedirect('https://h5.example.com/#/pages/community-detail/index?id='.$post->public_id);
    }

    private function configureWechatMini(): void
    {
        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'wx-robot-test');
        config()->set('wechat_auth.mini.app_secret', 'robot-test-secret');
    }

    public function test_forward_test_message_renders_selected_historical_posts(): void
    {
        [, $token] = $this->admin('operations_admin', 'multi-post-admin');
        $this->verify($token);
        $payload = $this->forwardPayload('多条渲染', '${index}/${total}: ${title}');
        $payload['template_config']['footer'] = '我是小尾巴';
        $created = $this->createForwardFromPayload($token, $payload);
        foreach ([1, 2, 3] as $offset) {
            $this->communityPost('历史帖子'.$offset, now()->subMinutes(10 - $offset));
        }

        $config = RobotForwardConfig::query()->where('public_id', $created['id'])->firstOrFail();
        $message = app(RobotForwardConfigService::class)->renderTestMessage($config, 3);

        $this->assertStringContainsString('1/3: 历史帖子3', $message);
        $this->assertStringContainsString('2/3: 历史帖子2', $message);
        $this->assertStringContainsString('3/3: 历史帖子1', $message);
        $this->assertSame(1, substr_count($message, '我是小尾巴'));
        $this->assertStringEndsWith('我是小尾巴', $message);
    }

    public function test_forward_config_defaults_to_published_only_and_pending_posts_are_excluded(): void
    {
        [, $token] = $this->admin('operations_admin', 'forward-post-status-admin');
        $this->verify($token);
        $created = $this->createForward($token, '帖子状态默认');

        $this->assertSame(['published'], $created['filter_rules']['post_statuses']);

        $published = $this->communityPost('已发布帖子', now()->subMinute());
        $pending = $this->communityPost('待审核帖子', now()->subMinute());
        $pending->update(['status' => 'pending_review', 'published_at' => null]);
        $config = RobotForwardConfig::query()->where('public_id', $created['id'])->firstOrFail();
        $message = app(RobotForwardConfigService::class)->renderTestMessage($config, 5);

        $this->assertStringContainsString($published->title, $message);
        $this->assertStringNotContainsString($pending->title, $message);
    }

    public function test_forward_config_can_include_pending_review_posts_when_explicitly_selected(): void
    {
        [, $token] = $this->admin('operations_admin', 'forward-post-status-include');
        $this->verify($token);
        $payload = $this->forwardPayload('含待审核');
        $payload['filter_rules']['post_statuses'] = ['published', 'pending_review'];
        $created = $this->createForwardFromPayload($token, $payload);

        $this->assertSame(['published', 'pending_review'], $created['filter_rules']['post_statuses']);

        $published = $this->communityPost('已发布帖子A', now()->subMinute());
        $pending = $this->communityPost('待审核帖子B', now()->subMinute());
        $pending->update(['status' => 'pending_review', 'published_at' => null]);
        $config = RobotForwardConfig::query()->where('public_id', $created['id'])->firstOrFail();
        $message = app(RobotForwardConfigService::class)->renderTestMessage($config, 10);

        $this->assertStringContainsString($published->title, $message);
        $this->assertStringContainsString($pending->title, $message);
    }

    public function test_forward_config_rejects_unknown_post_status_values(): void
    {
        [, $token] = $this->admin('operations_admin', 'forward-post-status-invalid');
        $this->verify($token);
        $payload = $this->forwardPayload('非法状态');
        $payload['filter_rules']['post_statuses'] = ['published', 'unknown'];

        $response = $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $payload);
        $response->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $errors = $response->json('details.errors');
        $this->assertSame('已选的 filter_rules.post_statuses.1 非法。', $errors['filter_rules.post_statuses.1'][0] ?? null);
    }

    private function communityPost(string $title = '转发测试帖子', ?Carbon $publishedAt = null): CommunityPost
    {
        $campus = Campus::query()->firstOrCreate(
            ['code' => 'robot-forward-campus'],
            ['name' => '机器人测试大学', 'status' => 'active', 'catalog_type' => 'manual', 'catalog_status' => 'manual'],
        );
        $user = User::query()->create(['campus_id' => $campus->id, 'nickname' => '发帖同学', 'phone' => '13800001234', 'status' => 'active']);

        return CommunityPost::query()->create([
            'campus_id' => $campus->id,
            'user_id' => $user->id,
            'title' => $title,
            'content' => '这是一条用于验证模板变量的正文内容',
            'category_code' => 'guide',
            'status' => 'published',
            'idempotency_key_hash' => hash('sha256', 'robot-post-'.uniqid('', true)),
            'published_at' => $publishedAt ?? now()->subMinutes(5),
            'like_count' => 7,
            'comment_count' => 3,
            'favorite_count' => 2,
        ]);
    }

    /** @return array<string, mixed> */
    private function forwardPayload(string $name = '晚间转发', string $template = '${index}. ${title}\n${content}'): array
    {
        $campus = Campus::query()->firstOrCreate(
            ['code' => 'robot-forward-campus'],
            ['name' => '机器人测试大学', 'status' => 'active', 'catalog_type' => 'manual', 'catalog_status' => 'manual'],
        );

        return [
            'name' => $name,
            'description' => '测试转发配置',
            'enabled' => true,
            'filter_rules' => [
                'platforms' => ['微信小程序'],
                'campus_id' => $campus->public_id, 'campus_name' => '客户端伪造名称',
                'categories' => [['code' => 'guide', 'name' => '客户端伪造分类']],
                'aggregate_interval' => 30, 'min_messages' => 1, 'max_messages' => 10,
                'quiet_start' => '23:00', 'quiet_end' => '07:00', 'active_weekdays' => [1, 2, 3, 4, 5],
            ],
            'template_config' => [
                'template' => $template, 'keywords' => ['招聘'], 'content_max_length' => 200,
            ],
            'robot_targets' => [[
                'device_id' => 'device-001', 'device_name' => 'iPhone 快照',
                'wxid' => 'wxid_owner', 'account_name' => '运营微信',
                'groups' => [
                    ['group_id' => 'group@chatroom', 'group_name' => '测试群'],
                    ['group_id' => 'group@chatroom', 'group_name' => '重复群'],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function createForward(string $token, string $name = '晚间转发'): array
    {
        return $this->createForwardFromPayload($token, $this->forwardPayload($name));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function createForwardFromPayload(string $token, array $payload): array
    {
        return $this->withToken($token)->postJson('/api/v1/admin/robot/forwards', $payload)
            ->assertCreated()->json('data');
    }

    private function verify(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])->assertOk();
    }

    /** @return array{AdminUser, string} */
    private function admin(string $roleCode, string $account): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return [$admin, $this->login($account)];
    }

    /** @param array<int, string> $permissions @return array{AdminUser, string} */
    private function adminWithPermissions(string $account, array $permissions): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $role = Role::query()->create(['code' => $account.'-role', 'name' => $account, 'system_role' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('code', $permissions)->pluck('id'));
        $admin->roles()->attach($role->id);

        return [$admin, $this->login($account)];
    }

    private function login(string $account): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account, 'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }
}
