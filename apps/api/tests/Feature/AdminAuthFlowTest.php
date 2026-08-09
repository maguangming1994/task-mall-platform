<?php

namespace Tests\Feature;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_refresh_profile_navigation_and_logout(): void
    {
        $admin = $this->createAdmin('platform_admin');
        $login = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()
            ->assertJsonPath('data.admin.display_name', '后台测试员')
            ->assertJsonFragment(['code' => 'tasks'])
            ->assertJsonMissing(['code' => 'finance']);

        $accessToken = $login->json('data.access_token');
        $refreshToken = $login->json('data.refresh_token');

        $this->withToken($accessToken)
            ->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'platform_admin');

        $refresh = $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertOk();
        $newAccessToken = $refresh->json('data.access_token');

        $this->withToken($accessToken)
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'ADMIN_AUTH_SESSION_REVOKED');

        $this->postJson('/api/v1/admin/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'ADMIN_AUTH_SESSION_REVOKED');

        $this->withToken($newAccessToken)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertNoContent();
        $this->withToken($newAccessToken)
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized();

        $this->assertDatabaseCount('admin_sessions', 2);
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin.auth.login']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin.auth.logout']);
    }

    public function test_login_failures_temporarily_lock_the_account_and_ip_pair(): void
    {
        config([
            'admin_auth.failure_threshold' => 2,
            'admin_auth.failure_window_seconds' => 300,
            'admin_auth.lock_seconds' => 300,
        ]);
        $admin = $this->createAdmin('platform_admin');

        foreach (range(1, 2) as $_) {
            $this->postJson('/api/v1/admin/auth/login', [
                'account' => $admin->account,
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertStatus(429)
            ->assertJsonPath('code', 'ADMIN_AUTH_LOCKED');
    }

    public function test_disabled_admin_session_stops_working_immediately(): void
    {
        $admin = $this->createAdmin('platform_admin');
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->json('data.access_token');

        $admin->update(['status' => 'disabled']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'ADMIN_AUTH_SESSION_REVOKED');
    }

    public function test_admin_can_change_password_and_other_sessions_are_revoked(): void
    {
        $admin = $this->createAdmin('platform_admin');
        $firstToken = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->json('data.access_token');
        $secondToken = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->json('data.access_token');

        $this->withToken($secondToken)->putJson('/api/v1/admin/auth/password', [
            'current_password' => 'StrongPassword123!',
            'new_password' => 'NewStrongPassword456!',
            'new_password_confirmation' => 'NewStrongPassword456!',
        ])->assertNoContent();

        $this->withToken($secondToken)->getJson('/api/v1/admin/me')->assertOk();
        $this->withToken($firstToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->assertTrue(Hash::check('NewStrongPassword456!', $admin->fresh()->password));
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin.auth.password_changed']);
    }

    public function test_permission_middleware_enforces_server_side_authorization(): void
    {
        Route::middleware(['api', 'auth.admin', 'admin.permission:finance.manage'])
            ->get('/api/v1/testing/admin-finance', fn () => ApiResponse::success());
        $admin = $this->createAdmin('platform_admin');
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/testing/admin-finance')
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
    }

    public function test_regular_user_token_cannot_be_used_as_an_admin_token(): void
    {
        $headers = [
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'admin-boundary-test-device',
        ];
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138999',
            'purpose' => 'login',
        ], $headers)->assertAccepted();
        $userToken = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138999',
            'verification_code' => '246810',
        ], $headers)->json('data.access_token');

        $this->withToken($userToken)
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'ADMIN_AUTH_REQUIRED');
    }

    public function test_admin_create_command_has_no_default_password_and_hashes_the_supplied_password(): void
    {
        $this->seed(RbacSeeder::class);

        $this->artisan('admin:create', [
            'account' => 'first-admin',
            '--name' => '首位管理员',
            '--role' => 'super_admin',
            '--password' => 'CommandPassword123!',
        ])->assertSuccessful();

        $admin = AdminUser::query()->where('account', 'first-admin')->firstOrFail();
        $this->assertTrue(Hash::check('CommandPassword123!', $admin->password));
        $this->assertNotSame('CommandPassword123!', $admin->password);
        $this->assertTrue($admin->hasPermission('rbac.manage'));
    }

    private function createAdmin(string $roleCode): AdminUser
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'admin-test',
            'display_name' => '后台测试员',
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $admin;
    }
}
