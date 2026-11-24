<?php

namespace Tests\Feature;

use AlibabaCloud\Dara\Models\RuntimeOptions;
use AlibabaCloud\SDK\Green\V20220302\Green;
use AlibabaCloud\SDK\Green\V20220302\Models\TextModerationPlusRequest;
use AlibabaCloud\SDK\Green\V20220302\Models\TextModerationPlusResponse;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityContentSecurityLog;
use App\Modules\Community\Services\AliyunContentSecurityClient;
use App\Modules\Community\Services\CommunityContentSecurityService;
use App\Modules\Community\Services\CommunityContentSecuritySettingsService;
use App\Modules\Community\Services\CommunityContentService;
use Darabonba\OpenApi\Models\Config;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CommunityContentSecurityFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.green.access_key_id' => null, 'services.green.access_key_secret' => null]);
    }

    public function test_wechat_risky_content_is_rejected_before_post_is_created(): void
    {
        $user = $this->userWithWechatIdentity();
        $this->fakeWechatToken();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 7200]),
            'api.weixin.qq.com/wxa/msg_sec_check*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'detail' => [['strategy' => 'content', 'errcode' => 0, 'suggest' => 'risky', 'label' => 20002]],
                'result' => ['suggest' => 'risky', 'label' => 20002],
            ]),
        ]);

        $this->expectException(ValidationException::class);
        try {
            app(CommunityContentService::class)->createPost($user, [
                'title' => '违规内容标题',
                'content' => '这是一段长度满足后台规则的帖子正文，但被微信内容安全判定为违规。',
            ], 'community-security-block');
        } finally {
            $this->assertDatabaseCount('community_posts', 0);
        }
    }

    public function test_wechat_review_suggestion_forces_manual_review_even_in_auto_publish_mode(): void
    {
        $this->setPostReviewMode('auto_publish');
        $user = $this->userWithWechatIdentity();
        $this->fakeWechatToken();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 7200]),
            'api.weixin.qq.com/wxa/msg_sec_check*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'result' => ['suggest' => 'review', 'label' => 100],
            ]),
        ]);

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '需要人工复审的帖子',
            'content' => '这是一段长度满足后台规则且微信建议人工复审的帖子正文内容。',
        ], 'community-security-review');

        $this->assertSame('pending_review', $post->status);
    }

    public function test_content_without_wechat_identity_forces_manual_review_in_auto_publish_mode(): void
    {
        $this->setPostReviewMode('auto_publish');
        // 手机号注册用户没有 wechat_mini 身份：机审无法执行，按 fail-closed 转人工审核。
        $user = User::factory()->create(['status' => 'active']);
        Http::fake();

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '未绑定微信的正常帖子',
            'content' => '这是一段长度满足后台规则的帖子正文，用户尚未绑定微信小程序身份。',
        ], 'community-security-skipped');

        $this->assertSame('pending_review', $post->status);
        Http::assertNothingSent();
    }

    public function test_wechat_review_suggestion_holds_comment_for_manual_review(): void
    {
        // 必须在解析 CommunityContentService 之前替换 token 服务，否则服务图里仍是真实实现。
        $this->fakeWechatToken();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 7200]),
            'api.weixin.qq.com/wxa/msg_sec_check*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'result' => ['suggest' => 'review', 'label' => 100],
            ]),
        ]);

        $author = User::factory()->create(['status' => 'active']);
        $commenter = $this->userWithWechatIdentity();
        $service = app(CommunityContentService::class);
        $post = $service->createPost($author, [
            'title' => '评论安全检测测试帖',
            'content' => '这是一段长度满足后台规则的帖子正文，用于评论内容安全检测流程。',
        ], 'community-security-comment-post');
        $post = $service->reviewPost(
            AdminUser::query()->create(['account' => 'security-comment-reviewer', 'display_name' => '安全审核员', 'password' => 'AdminPassword12345!']),
            $post,
            ['decision' => 'approve', 'note' => '帖子通过。']
        );

        $comment = $service->createComment($commenter, $post, [
            'content' => '这是一条长度满足奖励要求但被微信建议人工复审的评论内容。',
        ], 'community-security-comment-review');

        $this->assertSame('pending_review', $comment->status);
    }

    #[DataProvider('aliyunRiskLevels')]
    public function test_aliyun_sdk_risk_levels_are_mapped_to_review_verdicts(string $riskLevel, string $expectedVerdict, string $expectedRiskLevel): void
    {
        $client = $this->aliyunClientReturning([
            'Code' => 200,
            'RequestId' => 'ali-request-'.$riskLevel,
            'Data' => [
                'RiskLevel' => $riskLevel,
                'Result' => [['Label' => 'contraband'], ['Label' => 'contraband'], ['Label' => 'ad']],
            ],
        ]);

        $result = $client->scan($this->aliyunConfiguration(), 'SDK 模拟文本', 'risk-test-data-id');

        $this->assertSame($expectedVerdict, $result['verdict']);
        $this->assertSame($expectedRiskLevel, $result['risk_level']);
        $this->assertSame(['contraband', 'ad'], $result['risk_labels']);
        $this->assertSame('ali-request-'.$riskLevel, $result['request_id']);
        $this->assertIsInt($result['response_time_ms']);
    }

    public static function aliyunRiskLevels(): array
    {
        return [
            'high blocks' => ['high', CommunityContentSecurityService::BLOCK, 'high'],
            'medium reviews' => ['medium', CommunityContentSecurityService::REVIEW, 'medium'],
            'low passes' => ['low', CommunityContentSecurityService::PASS, 'low'],
            'none passes' => ['none', CommunityContentSecurityService::PASS, 'none'],
            'unknown conservatively reviews' => ['unexpected', CommunityContentSecurityService::REVIEW, 'medium'],
        ];
    }

    public function test_aliyun_non_success_sdk_response_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->aliyunClientReturning(['Code' => 500, 'Message' => 'SDK simulated failure'])
            ->scan($this->aliyunConfiguration(), 'SDK 模拟文本', 'risk-test-error');
    }

    public function test_aliyun_review_records_are_linked_to_created_post_with_campus_and_versions(): void
    {
        $this->setPostReviewMode('auto_publish');
        $this->configureAliyun();
        $campus = Campus::query()->create(['code' => 'security-campus', 'name' => '内容安全校区', 'status' => 'active']);
        $user = User::factory()->create(['campus_id' => $campus->id, 'status' => 'active']);
        $client = $this->createMock(AliyunContentSecurityClient::class);
        $client->expects($this->exactly(2))->method('scan')->willReturn($this->aliyunResult('review', 'medium'));
        $this->app->instance(AliyunContentSecurityClient::class, $client);

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '阿里复审帖子标题',
            'content' => '这是一段长度满足后台规则且阿里云建议人工复审的帖子正文内容。',
        ], 'aliyun-review-linked-post');

        $this->assertSame('pending_review', $post->status);
        $logs = CommunityContentSecurityLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['title', 'content'], $logs->pluck('content_field')->all());
        foreach ($logs as $log) {
            $this->assertSame($campus->id, $log->campus_id);
            $this->assertSame($post->id, $log->content_id);
            $this->assertSame($post->public_id, $log->content_public_id);
            $this->assertSame(1, $log->content_version_no);
            $this->assertSame('aliyun', $log->provider);
            $this->assertSame('review', $log->verdict);
            $this->assertSame('medium', $log->risk_level);
            $this->assertSame('阿里复审帖子标题', $log->subject_snapshot);
        }
    }

    public function test_title_review_does_not_skip_body_block_and_pre_insert_logs_keep_snapshots(): void
    {
        $this->setPostReviewMode('auto_publish');
        $this->configureAliyun();
        $campus = Campus::query()->create(['code' => 'security-block-campus', 'name' => '拦截校区', 'status' => 'active']);
        $user = User::factory()->create(['campus_id' => $campus->id, 'status' => 'active']);
        $client = $this->createMock(AliyunContentSecurityClient::class);
        $client->expects($this->exactly(2))->method('scan')->willReturnOnConsecutiveCalls(
            $this->aliyunResult('review', 'medium'),
            $this->aliyunResult('block', 'high'),
        );
        $this->app->instance(AliyunContentSecurityClient::class, $client);

        try {
            app(CommunityContentService::class)->createPost($user, [
                'title' => '标题需要人工复审',
                'content' => '标题虽然只需复审，但正文被判定为高风险并应在入库前拦截。',
            ], 'aliyun-title-review-body-block');
            $this->fail('高风险正文必须阻止发帖。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
        }

        $this->assertDatabaseCount('community_posts', 0);
        $logs = CommunityContentSecurityLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['review', 'block'], $logs->pluck('verdict')->all());
        $this->assertSame(['title', 'content'], $logs->pluck('content_field')->all());
        $this->assertSame('标题虽然只需复审，但正文被判定为高风险并应在入库前拦截。', $logs[1]->content_snapshot);
        foreach ($logs as $log) {
            $this->assertNull($log->content_id);
            $this->assertNull($log->content_public_id);
            $this->assertSame($campus->id, $log->campus_id);
            $this->assertSame($user->id, $log->user_id);
        }
    }

    public function test_aliyun_without_credentials_is_skipped_without_wechat_fallback(): void
    {
        $this->setPostReviewMode('auto_publish');
        $this->configureAliyun(configured: false);
        $user = $this->userWithWechatIdentity();
        $tokens = $this->createMock(WechatAccessTokenService::class);
        $tokens->expects($this->never())->method('token');
        $this->app->instance(WechatAccessTokenService::class, $tokens);
        $aliyun = $this->createMock(AliyunContentSecurityClient::class);
        $aliyun->expects($this->never())->method('scan');
        $this->app->instance(AliyunContentSecurityClient::class, $aliyun);
        Http::fake();

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '阿里凭证未配置降级',
            'content' => '阿里凭证未配置时按 fail-closed 策略转人工审核，不串联调用微信。',
        ], 'aliyun-not-configured-skip');

        $this->assertSame('pending_review', $post->status);
        $this->assertSame(['not-configured', 'not-configured'], CommunityContentSecurityLog::query()->orderBy('id')->pluck('skip_reason')->all());
        $this->assertSame(['aliyun', 'aliyun'], CommunityContentSecurityLog::query()->orderBy('id')->pluck('provider')->all());
        Http::assertNothingSent();
    }

    public function test_aliyun_exception_is_logged_as_skipped_and_forces_manual_review(): void
    {
        $this->setPostReviewMode('auto_publish');
        $this->configureAliyun();
        $user = User::factory()->create(['status' => 'active']);
        $client = $this->createMock(AliyunContentSecurityClient::class);
        $client->expects($this->exactly(2))->method('scan')->willThrowException(new RuntimeException('SDK simulated outage'));
        $this->app->instance(AliyunContentSecurityClient::class, $client);

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '阿里异常降级帖子',
            'content' => '阿里厂商调用异常时记录降级结果，并按 fail-closed 策略转人工审核。',
        ], 'aliyun-error-skip-post');

        $this->assertSame('pending_review', $post->status);
        $logs = CommunityContentSecurityLog::query()->orderBy('id')->get();
        $this->assertSame(['skipped', 'skipped'], $logs->pluck('verdict')->all());
        $this->assertSame(['error', 'error'], $logs->pluck('skip_reason')->all());
        $this->assertNotNull($logs[0]->content_public_id);
    }

    public function test_disabled_external_scan_is_logged_as_skipped_without_calling_provider(): void
    {
        $this->setPostReviewMode('auto_publish');
        $this->configureAliyun(enabled: false);
        $user = User::factory()->create(['status' => 'active']);
        $client = $this->createMock(AliyunContentSecurityClient::class);
        $client->expects($this->never())->method('scan');
        $this->app->instance(AliyunContentSecurityClient::class, $client);

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '外部机审关闭降级',
            'content' => '管理员显式关闭外部机审时保持原有发布规则，不因未执行检测而强制转审。',
        ], 'aliyun-disabled-skip-post');

        $this->assertSame('published', $post->status);
        $this->assertSame(['disabled', 'disabled'], CommunityContentSecurityLog::query()->orderBy('id')->pluck('skip_reason')->all());
    }

    /** @return array<string,mixed> */
    private function aliyunConfiguration(): array
    {
        return [
            'access_key_id' => 'sdk-test-access-key-id',
            'access_key_secret' => 'sdk-test-access-key-secret',
            'region' => 'cn-shanghai',
            'endpoint' => 'green-cip.cn-shanghai.aliyuncs.com',
            'service' => 'ugc_moderation_byllm',
        ];
    }

    private function configureAliyun(bool $configured = true, bool $enabled = true): void
    {
        app(CommunityContentSecuritySettingsService::class)->update([
            ...$this->aliyunConfiguration(),
            'provider' => 'aliyun',
            'enabled' => $enabled,
            'access_key_id' => $configured ? 'sdk-test-access-key-id' : null,
            'access_key_secret' => $configured ? 'sdk-test-access-key-secret' : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function aliyunResult(string $verdict, string $riskLevel): array
    {
        return [
            'verdict' => $verdict,
            'risk_level' => $riskLevel,
            'risk_labels' => ['sdk-label'],
            'request_id' => 'sdk-request-id',
            'response_time_ms' => 12,
        ];
    }

    private function aliyunClientReturning(array $payload): AliyunContentSecurityClient
    {
        $green = \Mockery::mock(Green::class);
        $green->shouldReceive('textModerationPlusWithOptions')->once()->withArgs(
            function (TextModerationPlusRequest $request, RuntimeOptions $runtime): bool {
                $parameters = json_decode((string) $request->serviceParameters, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('ugc_moderation_byllm', $request->service);
                $this->assertSame('SDK 模拟文本', $parameters['content']);
                $this->assertNotEmpty($parameters['dataId']);

                return true;
            },
        )->andReturn(TextModerationPlusResponse::fromMap(['body' => $payload]));

        return new class($green) extends AliyunContentSecurityClient
        {
            public function __construct(private readonly Green $green) {}

            protected function createClient(Config $config): Green
            {
                return $this->green;
            }
        };
    }

    /**
     * 绕过真实微信凭据配置：让 access_token 服务直接返回假 token，
     * 网络层由 Http::fake 接管。createMock 不调用构造函数。
     */
    private function fakeWechatToken(): void
    {
        $tokenService = $this->createMock(WechatAccessTokenService::class);
        $tokenService->method('token')->willReturn('fake-token');
        $this->app->instance(WechatAccessTokenService::class, $tokenService);
    }

    private function userWithWechatIdentity(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'wechat_mini',
            'provider_user_id' => 'mini-'.$user->id,
            'provider_user_value' => 'openid-security-test-'.$user->id,
            'union_id' => null,
            'union_value' => null,
            'metadata' => ['app_id_hash' => hash('sha256', 'wx-security-test')],
            'last_login_at' => now(),
        ]);

        return $user;
    }

    private function setPostReviewMode(string $mode): void
    {
        $definitionId = DB::table('system_setting_definitions')
            ->where('setting_key', 'community.post_review_mode')
            ->value('id');
        DB::table('system_setting_versions')->insert([
            'setting_definition_id' => $definitionId,
            'version' => 1,
            'value' => json_encode($mode, JSON_UNESCAPED_UNICODE),
            'source' => 'publish',
            'reason' => '测试设置',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::forget('system-setting:active:community.post_review_mode');
    }
}
