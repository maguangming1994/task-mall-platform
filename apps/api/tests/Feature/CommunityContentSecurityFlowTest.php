<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Community\Services\CommunityContentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommunityContentSecurityFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
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

    public function test_content_without_wechat_identity_falls_back_and_auto_publishes(): void
    {
        $this->setPostReviewMode('auto_publish');
        // 手机号注册用户没有 wechat_mini 身份：即使微信侧不可用也必须降级放行，不能卡住发帖。
        $user = User::factory()->create(['status' => 'active']);
        Http::fake();

        $post = app(CommunityContentService::class)->createPost($user, [
            'title' => '未绑定微信的正常帖子',
            'content' => '这是一段长度满足后台规则的帖子正文，用户尚未绑定微信小程序身份。',
        ], 'community-security-skipped');

        $this->assertSame('published', $post->status);
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
