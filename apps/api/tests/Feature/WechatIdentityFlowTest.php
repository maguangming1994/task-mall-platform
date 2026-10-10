<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\IdentityHasher;
use App\Modules\Settings\Models\SystemSettingDefinition;
use App\Modules\Settings\Models\SystemSettingVersion;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatIdentityFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mini_and_official_account_union_id_resolve_to_one_user(): void
    {
        $mini = $this->wechatLogin('mini', 'local:mini-open-1:union-shared-1')
            ->assertOk()
            ->assertJsonPath('data.user.phone_bound', false);
        $official = $this->wechatLogin('h5', 'local:oa-open-1:union-shared-1')
            ->assertOk();

        $this->assertSame($mini->json('data.user.id'), $official->json('data.user.id'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_identities', 2);

        $stored = DB::table('user_identities')->where('provider', 'wechat_mini')->first();
        $this->assertNotSame('mini-open-1', $stored->provider_user_id);
        $this->assertNotSame('mini-open-1', $stored->provider_user_value);
        $this->assertNotSame('union-shared-1', $stored->union_id);
        $this->assertNotSame('union-shared-1', $stored->union_value);
    }

    public function test_wechat_user_can_bind_a_verified_phone(): void
    {
        $login = $this->wechatLogin('mini', 'local:mini-open-bind:union-bind');
        $token = $login->json('data.access_token');
        $headers = $this->headers('wechat-mini', 'mini-bind-device');

        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138880',
            'purpose' => 'bind_phone',
        ], $headers)->assertAccepted();

        $this->withToken($token)
            ->putJson('/api/v1/me/phone', [
                'new_phone' => '13800138880',
                'new_phone_verification_code' => '246810',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_bound', true)
            ->assertJsonPath('data.phone_masked', '138****8880');

        $this->assertDatabaseHas('user_identities', ['provider' => 'phone']);
    }

    public function test_unbound_wechat_session_is_limited_to_the_phone_binding_flow(): void
    {
        $login = $this->wechatLogin('mini', 'local:mini-open-limited:union-limited');
        $token = $login->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone_bound', false);

        $this->withToken($token)
            ->getJson('/api/v1/me/legal-consents/pending?scope=account')
            ->assertOk();

        foreach (['/api/v1/me/asset-overview', '/api/v1/orders', '/api/v1/community/my-posts'] as $path) {
            $this->withToken($token)
                ->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('code', 'PHONE_BINDING_REQUIRED');
        }
    }

    public function test_wechat_user_can_bind_phone_from_mini_program_authorization(): void
    {
        $login = $this->wechatLogin('mini', 'local:mini-open-phone:union-phone');
        $token = $login->json('data.access_token');

        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'test-mini-app-id');
        config()->set('wechat_auth.mini.app_secret', 'test-mini-app-secret');
        Cache::clear();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/wxa/business/getuserphonenumber*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'phone_info' => [
                    'phoneNumber' => '13800138882',
                    'purePhoneNumber' => '13800138882',
                    'countryCode' => '86',
                ],
            ]),
        ]);

        $this->withToken($token)
            ->putJson('/api/v1/me/phone/wechat', ['code' => 'phone-authorization-code'])
            ->assertOk()
            ->assertJsonPath('data.phone_bound', true)
            ->assertJsonPath('data.phone_masked', '138****8882');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.weixin.qq.com/wxa/business/getuserphonenumber')
            && str_contains($request->url(), 'access_token=test-access-token')
            && $request['code'] === 'phone-authorization-code'
        );
        $this->assertDatabaseHas('user_identities', [
            'provider' => 'phone',
            'metadata' => json_encode(['source' => 'wechat_phone_authorization']),
        ]);
    }

    public function test_wechat_phone_authorization_switches_to_the_existing_phone_account(): void
    {
        $phoneHeaders = $this->headers('h5-browser', 'wechat-phone-existing-device');
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138883',
            'purpose' => 'login',
        ], $phoneHeaders)->assertAccepted();
        $phoneUser = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138883',
            'verification_code' => '246810',
        ], $phoneHeaders)->assertOk();

        $wechat = $this->wechatLogin('mini', 'local:mini-phone-merge:union-phone-merge')->assertOk();
        $wechatToken = $wechat->json('data.access_token');

        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'test-mini-app-id');
        config()->set('wechat_auth.mini.app_secret', 'test-mini-app-secret');
        Cache::clear();
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/wxa/business/getuserphonenumber*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'phone_info' => [
                    'phoneNumber' => '13800138883',
                    'purePhoneNumber' => '13800138883',
                    'countryCode' => '86',
                ],
            ]),
        ]);

        $binding = $this->withToken($wechatToken)
            ->putJson('/api/v1/me/phone/wechat', ['code' => 'existing-phone-code'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $phoneUser->json('data.user.id'))
            ->assertJsonPath('data.account_resolution.outcome', 'merged_into_existing_account');

        $this->withToken($wechatToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($binding->json('data.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $phoneUser->json('data.user.id'));
    }

    public function test_rejected_mini_login_code_returns_client_auth_failure(): void
    {
        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'test-mini-app-id');
        config()->set('wechat_auth.mini.app_secret', 'test-mini-app-secret');
        Cache::clear();
        Http::fake([
            'api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'errcode' => 40029,
                'errmsg' => 'invalid code',
            ]),
        ]);

        $this->wechatLogin('mini', 'rejected-login-code')
            ->assertStatus(422)
            ->assertJsonPath('code', 'WECHAT_AUTH_FAILED')
            ->assertJsonPath('message', '微信临时登录凭证无效或已过期。');
    }

    public function test_invalid_wechat_appsecret_is_reported_as_service_unavailable(): void
    {
        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', 'test-mini-app-id');
        config()->set('wechat_auth.mini.app_secret', 'wrong-secret');
        Cache::clear();
        Http::fake([
            'api.weixin.qq.com/sns/jscode2session*' => Http::response([
                'errcode' => 40125,
                'errmsg' => 'invalid appsecret',
            ]),
        ]);

        $this->wechatLogin('mini', 'config-error-code')
            ->assertStatus(503)
            ->assertJsonPath('code', 'WECHAT_PROVIDER_UNAVAILABLE')
            ->assertJsonPath('message', '微信登录服务暂时不可用，请稍后重试或使用手机号登录。');
    }

    public function test_unconfigured_wechat_phone_authorization_returns_a_safe_fallback(): void
    {
        $login = $this->wechatLogin('mini', 'local:mini-open-unconfigured:union-unconfigured');
        $token = $login->json('data.access_token');

        config()->set('wechat_auth.driver', 'wechat');
        config()->set('wechat_auth.mini.app_id', null);
        config()->set('wechat_auth.mini.app_secret', null);

        $this->withToken($token)
            ->putJson('/api/v1/me/phone/wechat', ['code' => 'phone-authorization-code'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'WECHAT_PHONE_PROVIDER_NOT_CONFIGURED')
            ->assertJsonPath('message', '微信手机号授权暂时不可用，请使用短信验证码完成绑定，或稍后重试。');
    }

    public function test_union_collision_creates_a_pending_conflict_without_silent_merge(): void
    {
        $hasher = app(IdentityHasher::class);
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([[$first, 'wechat_mini', 'open-first'], [$second, 'wechat_oa', 'open-second']] as [$user, $provider, $openid]) {
            UserIdentity::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $hasher->hash($openid),
                'provider_user_value' => $openid,
                'union_id' => $hasher->hash('union-collision'),
                'union_value' => 'union-collision',
                'metadata' => [],
            ]);
        }

        $this->wechatLogin('mini', 'local:open-third:union-collision')
            ->assertConflict()
            ->assertJsonPath('code', 'IDENTITY_MERGE_REQUIRED')
            ->assertJsonStructure(['details' => ['conflict_id']]);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('identity_conflicts', ['kind' => 'union_collision', 'status' => 'pending']);
    }

    public function test_verified_phone_conflict_merges_wechat_transition_account_and_switches_session(): void
    {
        $phoneHeaders = $this->headers('h5-browser', 'existing-phone-device');
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138881',
            'purpose' => 'login',
        ], $phoneHeaders)->assertAccepted();
        $phoneUser = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138881',
            'verification_code' => '246810',
        ], $phoneHeaders)->assertOk();

        $wechat = $this->wechatLogin('mini', 'local:merge-open:merge-union')->assertOk();
        $wechatToken = $wechat->json('data.access_token');
        $sourceUser = User::query()->where('public_id', $wechat->json('data.user.id'))->firstOrFail();
        $wechatHeaders = $this->headers('wechat-mini', 'mini-merge-device');
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138881',
            'purpose' => 'bind_phone',
        ], $wechatHeaders)->assertAccepted();
        $binding = $this->withToken($wechatToken)->putJson('/api/v1/me/phone', [
            'new_phone' => '13800138881',
            'new_phone_verification_code' => '246810',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $phoneUser->json('data.user.id'))
            ->assertJsonPath('data.user.phone_bound', true)
            ->assertJsonPath('data.account_resolution.outcome', 'merged_into_existing_account')
            ->assertJsonPath('data.account_resolution.message', '手机号已关联原账户，已合并微信身份并为你切换登录。');

        $this->withToken($wechatToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
        $this->withToken($binding->json('data.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $phoneUser->json('data.user.id'));
        $this->assertSame(
            $phoneUser->json('data.user.id'),
            UserIdentity::query()->where('provider', 'wechat_mini')->firstOrFail()->user->public_id,
        );
        $this->assertDatabaseHas('users', [
            'id' => $sourceUser->id,
            'status' => 'merged',
            'merged_into_user_id' => User::query()->where('public_id', $phoneUser->json('data.user.id'))->value('id'),
        ]);
        $this->assertDatabaseHas('identity_conflicts', [
            'source_user_id' => $sourceUser->id,
            'status' => 'resolved',
            'resolution' => 'verified_phone_account_merge',
        ]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'identity_conflict.auto_resolved']);
    }

    public function test_capabilities_only_advertise_wechat_for_the_matching_runtime(): void
    {
        $this->getJson('/api/v1/client-capabilities', $this->headers('wechat-mini', 'cap-mini'))
            ->assertOk()
            ->assertJsonPath('data.login_methods.0', 'wechat_mini')
            ->assertJsonPath('data.wechat_payment_enabled', true)
            ->assertJsonPath('data.wallet_payment_enabled', true)
            ->assertJsonPath('data.cash_payment_methods.0', 'wechat')
            ->assertJsonPath('data.cash_payment_methods.1', 'wallet')
            ->assertJsonPath('data.payment_methods.0.label', '微信全额支付');
        $this->getJson('/api/v1/client-capabilities', $this->headers('h5-wechat', 'cap-h5'))
            ->assertOk()
            ->assertJsonPath('data.login_methods.0', 'wechat_oa');
        $this->getJson('/api/v1/client-capabilities', $this->headers('h5-browser', 'cap-browser'))
            ->assertOk()
            ->assertJsonMissing(['wechat_mini'])
            ->assertJsonMissing(['wechat_oa']);
    }

    public function test_capabilities_follow_the_published_mall_wallet_payment_switch(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $definition = SystemSettingDefinition::query()->where('setting_key', 'mall.wallet_payment_enabled')->firstOrFail();
        SystemSettingVersion::query()->create([
            'setting_definition_id' => $definition->id,
            'version' => 1,
            'value' => false,
            'source' => 'publish',
            'reason' => '测试商城余额支付关闭后的客户端能力。',
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/client-capabilities', $this->headers('h5-browser', 'wallet-disabled-capability'))
            ->assertOk()
            ->assertJsonPath('data.wallet_payment_enabled', false)
            ->assertJsonPath('data.cash_payment_methods', ['wechat'])
            ->assertJsonPath('data.wallet_payment_unavailable_message', '商城余额支付已由平台关闭，请选择微信支付。');
    }

    public function test_capabilities_hide_unconfigured_production_wechat_payment_with_a_chinese_reason(): void
    {
        config()->set('wechat_payment.driver', 'wechat');
        config()->set('wechat_payment.merchant_id', null);

        $this->getJson('/api/v1/client-capabilities', $this->headers('h5-browser', 'wechat-unconfigured-capability'))
            ->assertOk()
            ->assertJsonPath('data.wechat_payment_enabled', false)
            ->assertJsonPath('data.cash_payment_methods', ['wallet'])
            ->assertJsonPath('data.wechat_payment_unavailable_message', '浏览器微信支付尚未配置完成，请改用余额支付或稍后再试。');
    }

    private function wechatLogin(string $channel, string $code)
    {
        $mini = $channel === 'mini';

        return $this->postJson(
            $mini ? '/api/v1/auth/wechat-mini/login' : '/api/v1/auth/wechat-h5/login',
            ['code' => $code],
            $this->headers($mini ? 'wechat-mini' : 'h5-wechat', $mini ? 'mini-test-device' : 'h5-test-device'),
        );
    }

    /** @return array<string, string> */
    private function headers(string $platform, string $device): array
    {
        return ['X-Client-Platform' => $platform, 'X-Device-Id' => $device];
    }
}
