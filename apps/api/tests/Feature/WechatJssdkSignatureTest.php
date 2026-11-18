<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatJssdkSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'app.url' => 'https://rwsc.example.test',
            'wechat_auth.driver' => 'wechat',
            'wechat_auth.official_account.app_id' => 'wx1234567890abcdef',
            'wechat_auth.official_account.app_secret' => 'official-secret',
            'wechat_auth.official_account.oauth_redirect_domain' => null,
        ]);
    }

    public function test_signature_endpoint_returns_deterministic_signature(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'token-1', 'expires_in' => 7200]),
            'api.weixin.qq.com/cgi-bin/ticket/getticket*' => Http::response(['errcode' => 0, 'errmsg' => 'ok', 'ticket' => 'ticket-1']),
        ]);

        $response = $this->getJson('/api/v1/wechat/jssdk-signature?url='.urlencode('https://rwsc.example.test/#/pages/task-detail/index'))
            ->assertOk()
            ->assertJsonPath('data.appId', 'wx1234567890abcdef');

        $data = $response->json('data');
        $expected = sha1(sprintf(
            'jsapi_ticket=ticket-1&noncestr=%s&timestamp=%d&url=https://rwsc.example.test/',
            $data['nonceStr'],
            $data['timestamp'],
        ));

        $this->assertSame($expected, $data['signature']);
        Http::assertSentCount(2);
    }

    public function test_signature_endpoint_rejects_foreign_host(): void
    {
        $this->getJson('/api/v1/wechat/jssdk-signature?url='.urlencode('https://evil.example.com/page'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'WECHAT_JSSDK_URL_NOT_ALLOWED');
    }

    public function test_signature_endpoint_reports_missing_configuration(): void
    {
        config([
            'wechat_auth.driver' => 'local',
            'wechat_auth.official_account.app_secret' => null,
        ]);

        $this->getJson('/api/v1/wechat/jssdk-signature?url='.urlencode('https://rwsc.example.test/'))
            ->assertStatus(503)
            ->assertJsonPath('code', 'WECHAT_JSSDK_UNAVAILABLE');
    }
}
