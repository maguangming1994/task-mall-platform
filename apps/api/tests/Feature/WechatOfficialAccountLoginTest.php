<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatOfficialAccountLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_account_login_persists_nickname_and_avatar_from_userinfo(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $this->fakeOfficialAccountSettings();

        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response([
                'access_token' => 'oa-access-token',
                'openid' => 'oa-openid-profile-1',
                'unionid' => 'oa-union-profile-1',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'openid' => 'oa-openid-profile-1',
                'nickname' => '公众号同学',
                'headimgurl' => 'https://thirdwx.qlogo.cn/mmopen/avatar.jpg',
                'sex' => 1,
                'city' => '赣州',
                'province' => '江西',
                'country' => '中国',
            ]),
        ]);

        $this->wechatH5Login('oa-login-code', 'oa-profile-device')
            ->assertOk()
            ->assertJsonPath('data.user.phone_bound', false);

        $user = User::query()->firstOrFail();
        $this->assertSame('公众号同学', $user->nickname);

        $identity = UserIdentity::query()->firstOrFail();
        $profile = $identity->metadata['wechat_profile'] ?? [];
        $this->assertSame('公众号同学', $profile['nickname'] ?? null);
        $this->assertSame('https://thirdwx.qlogo.cn/mmopen/avatar.jpg', $profile['headimgurl'] ?? null);
    }

    public function test_userinfo_failure_degrades_to_openid_only_login(): void
    {
        $this->seed(SystemSettingDefinitionSeeder::class);
        $this->fakeOfficialAccountSettings();

        Http::fake([
            'api.weixin.qq.com/sns/oauth2/access_token*' => Http::response([
                'access_token' => 'oa-access-token',
                'openid' => 'oa-openid-fallback',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/sns/userinfo*' => Http::response([
                'errcode' => 40001,
                'errmsg' => 'invalid credential',
            ]),
        ]);

        $this->wechatH5Login('oa-fallback-code', 'oa-fallback-device')->assertOk();

        $user = User::query()->firstOrFail();
        $this->assertStringStartsWith('微信用户', (string) $user->nickname);

        $identity = UserIdentity::query()->firstOrFail();
        $this->assertArrayNotHasKey('wechat_profile', $identity->metadata ?? []);
    }

    public function test_admin_user_list_exposes_masked_official_account_channel(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'wechat-oa-reviewer',
            'display_name' => '公众号运营管理员',
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'platform_admin')->value('id'));

        $user = User::factory()->create([
            'nickname' => '公众号昵称用户',
            'status' => 'active',
        ]);
        UserIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'wechat_official_account',
            'provider_user_id' => hash('sha256', 'oa-openid-1234567890'),
            'provider_user_value' => 'oa-openid-1234567890',
            'union_value' => 'union-0987654321',
            'metadata' => [
                'source' => 'wechat_code_exchange',
                'wechat_profile' => [
                    'nickname' => '公众号昵称',
                    'headimgurl' => 'https://thirdwx.qlogo.cn/mmopen/avatar.jpg',
                ],
            ],
            'last_login_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'wechat-oa-reviewer',
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        $response = $this->withToken($token)->getJson('/api/v1/admin/user-center/users')->assertOk();
        $item = collect($response->json('data.items'))->firstWhere('id', $user->public_id);

        $this->assertNotNull($item);
        $this->assertTrue($item['verification']['wechat']);
        $this->assertTrue($item['wechat']['bound']);
        $this->assertSame('wechat_official_account', $item['wechat']['channels'][0]['provider']);
        $this->assertSame('公众号', $item['wechat']['channels'][0]['label']);
        $this->assertSame('oa-o****7890', $item['wechat']['channels'][0]['openid_masked']);
        $this->assertSame('unio****4321', $item['wechat']['channels'][0]['unionid_masked']);
        $this->assertSame('公众号昵称', $item['wechat']['channels'][0]['nickname']);
    }

    private function wechatH5Login(string $code, string $device)
    {
        return $this->postJson('/api/v1/auth/wechat-h5/login', ['code' => $code], [
            'X-Client-Platform' => 'h5-wechat',
            'X-Device-Id' => $device,
        ]);
    }

    /** 用 mock 提供公众号凭据，避免依赖加密凭据表。 */
    private function fakeOfficialAccountSettings(): void
    {
        $settings = \Mockery::mock(WechatProviderSettingsService::class);
        $settings->shouldReceive('effective')->with('official_account')->andReturn([
            'driver' => 'wechat',
            'app_id' => 'test-oa-app-id',
            'app_secret' => 'test-oa-secret',
            'token' => null,
            'encoding_aes_key' => null,
            'timeout_seconds' => 5,
            'login_enabled' => true,
        ]);
        $this->app->instance(WechatProviderSettingsService::class, $settings);
    }
}
