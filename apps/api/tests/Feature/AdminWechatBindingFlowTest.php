<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminMfaFactor;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\AdminWechatBinding;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Services\WechatIdentityProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminWechatBindingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_stores_only_hashed_openid_and_enables_passwordless_login(): void
    {
        $this->fakeWechatIdentity('wx-code-12345678', 'openid-admin-001');
        $admin = $this->createAdmin('platform_admin');
        $token = $this->login($admin);

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/wechat/bind', ['code' => 'wx-code-12345678'])
            ->assertOk()
            ->assertJsonPath('data.bound', true);

        $binding = AdminWechatBinding::query()->firstOrFail();
        $this->assertSame(
            hash_hmac('sha256', 'openid-admin-001', (string) config('admin_auth.hash_secret')),
            $binding->openid_hash,
        );
        $this->assertDatabaseMissing('admin_wechat_bindings', ['openid_hash' => 'openid-admin-001']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin.auth.wechat_bound']);

        $login = $this->postJson('/api/v1/admin/auth/wechat/login', ['code' => 'wx-code-12345678'])
            ->assertOk()
            ->assertJsonPath('data.admin.account', $admin->account);

        $login->assertCookie((string) config('admin_auth.remember_cookie_name'));
        $this->assertDatabaseHas('admin_sessions', [
            'admin_user_id' => $admin->id,
            'login_method' => 'wechat',
        ]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin.auth.wechat_login']);
    }

    public function test_unbound_wechat_cannot_log_in(): void
    {
        $this->fakeWechatIdentity('wx-code-unbound1', 'openid-unbound');

        $this->postJson('/api/v1/admin/auth/wechat/login', ['code' => 'wx-code-unbound1'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'WECHAT_BINDING_NOT_FOUND');
    }

    public function test_one_wechat_cannot_be_bound_to_two_admins(): void
    {
        $this->fakeWechatIdentity('wx-code-conflict1', 'openid-shared');
        $first = $this->createAdmin('platform_admin', 'admin-first');
        $second = $this->createAdmin('platform_admin', 'admin-second');

        $this->withToken($this->login($first))
            ->postJson('/api/v1/admin/auth/wechat/bind', ['code' => 'wx-code-conflict1'])
            ->assertOk();

        $this->withToken($this->login($second))
            ->postJson('/api/v1/admin/auth/wechat/bind', ['code' => 'wx-code-conflict1'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'WECHAT_ALREADY_BOUND');
    }

    public function test_mfa_admin_still_needs_one_time_code_after_wechat_login(): void
    {
        $this->fakeWechatIdentity('wx-code-mfa-1234', 'openid-mfa');
        $admin = $this->createAdmin('platform_admin');
        $this->withToken($this->login($admin))
            ->postJson('/api/v1/admin/auth/wechat/bind', ['code' => 'wx-code-mfa-1234'])
            ->assertOk();

        $admin->update(['requires_mfa' => true]);
        AdminMfaFactor::query()->create([
            'admin_user_id' => $admin->id,
            'type' => 'totp',
            'secret_ciphertext' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'status' => 'active',
            'confirmed_at' => now(),
            'last_used_at' => null,
        ]);

        $this->postJson('/api/v1/admin/auth/wechat/login', ['code' => 'wx-code-mfa-1234'])
            ->assertStatus(202)
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonStructure(['data' => ['challenge_id', 'challenge_token', 'expires_at']]);
    }

    public function test_unbind_removes_binding_and_revokes_wechat_sessions(): void
    {
        $this->fakeWechatIdentity('wx-code-unbind1', 'openid-unbind');
        $admin = $this->createAdmin('platform_admin');
        $passwordToken = $this->login($admin);

        $this->withToken($passwordToken)
            ->postJson('/api/v1/admin/auth/wechat/bind', ['code' => 'wx-code-unbind1'])
            ->assertOk();
        $this->postJson('/api/v1/admin/auth/wechat/login', ['code' => 'wx-code-unbind1'])->assertOk();

        $wechatSession = AdminSession::query()
            ->where('admin_user_id', $admin->id)
            ->where('login_method', 'wechat')
            ->firstOrFail();

        $this->withToken($passwordToken)
            ->deleteJson('/api/v1/admin/auth/wechat/binding')
            ->assertNoContent();

        $this->assertDatabaseMissing('admin_wechat_bindings', ['admin_user_id' => $admin->id]);
        $this->assertNotNull($wechatSession->fresh()->revoked_at);
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin.auth.wechat_unbound']);

        // 密码会话不受影响，解绑后仍可继续使用
        $this->withToken($passwordToken)->getJson('/api/v1/admin/me')->assertOk();
    }

    private function fakeWechatIdentity(string $code, string $openid): void
    {
        $this->mock(WechatIdentityProvider::class, function (MockInterface $mock) use ($code, $openid): void {
            $mock->shouldReceive('exchange')
                ->with('h5', $code)
                ->andReturn([
                    'openid' => $openid,
                    'unionid' => null,
                    'app_id_hash' => hash('sha256', 'wx-test-appid'),
                ]);
        });
    }

    private function login(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()->json('data.access_token');
    }

    private function createAdmin(string $roleCode, string $account = 'admin-test'): AdminUser
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => '后台测试员',
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $admin;
    }
}
