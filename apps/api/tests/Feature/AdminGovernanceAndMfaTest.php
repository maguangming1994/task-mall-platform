<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Admin\Services\TotpService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGovernanceAndMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_assign_disable_and_revoke_without_self_lockout(): void
    {
        [, $token] = $this->admin('governance-root');
        $created = $this->withToken($token)->postJson('/api/v1/admin/admin-users', [
            'account' => 'ops-one', 'display_name' => '运营一号', 'password' => 'OpsPassword12345!', 'role_codes' => ['operations_admin'],
        ])->assertCreated()->assertJsonPath('data.roles.0', 'operations_admin');
        $id = $created->json('data.id');
        $this->withToken($token)->putJson("/api/v1/admin/admin-users/{$id}/roles", [
            'role_codes' => ['platform_admin'], 'reason' => '调整为平台任务运营职责。',
        ])->assertOk()->assertJsonPath('data.roles.0', 'platform_admin');

        $opsToken = $this->postJson('/api/v1/admin/auth/login', ['account' => 'ops-one', 'password' => 'OpsPassword12345!'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson("/api/v1/admin/admin-users/{$id}/revoke-sessions", ['reason' => '权限调整后撤销旧会话。'])
            ->assertOk()->assertJsonPath('data.revoked_count', 1);
        $this->withToken($opsToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->withToken($token)->putJson("/api/v1/admin/admin-users/{$id}/status", ['status' => 'disabled', 'reason' => '测试停用并即时失效。'])
            ->assertOk()->assertJsonPath('data.status', 'disabled');

        $root = AdminUser::query()->where('account', 'governance-root')->firstOrFail();
        $this->withToken($token)->putJson("/api/v1/admin/admin-users/{$root->public_id}/status", ['status' => 'disabled', 'reason' => '尝试停用自己。'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin_user.roles_update']);
    }

    public function test_high_risk_admin_changes_require_recent_verification(): void
    {
        [, $token] = $this->admin('stale-root');
        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);
        $this->withToken($token)->postJson('/api/v1/admin/admin-users', [
            'account' => 'blocked-create', 'display_name' => '应阻止', 'password' => 'BlockedPassword123!', 'role_codes' => ['auditor'],
        ])->assertForbidden()->assertJsonPath('code', 'ADMIN_RECENT_VERIFICATION_REQUIRED');
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])
            ->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/admin-users', [
            'account' => 'allowed-create', 'display_name' => '允许创建', 'password' => 'AllowedPassword123!', 'role_codes' => ['auditor'],
        ])->assertCreated();
    }

    public function test_super_admin_can_create_and_edit_roles_but_cannot_weaken_super_admin(): void
    {
        [, $token] = $this->admin('role-manager');
        $created = $this->withToken($token)->postJson('/api/v1/admin/roles', [
            'code' => 'product_operator',
            'name' => '商品运营专员',
            'description' => '负责商品资料、库存和运营活动。',
            'permission_codes' => ['product.manage', 'operations.manage', 'audit.view_own'],
            'reason' => '新增商品运营岗位并配置最小权限。',
        ])->assertCreated()
            ->assertJsonPath('data.name', '商品运营专员')
            ->assertJsonPath('data.system_role', false)
            ->assertJsonPath('data.permission_items.0.name', '查看本人审计');

        $this->withToken($token)->putJson('/api/v1/admin/roles/product_operator', [
            'name' => '高级商品运营专员',
            'description' => '增加履约管理职责。',
            'permission_codes' => ['product.manage', 'fulfillment.manage', 'operations.manage', 'audit.view_own'],
            'reason' => '岗位增加库存和履约管理职责。',
        ])->assertOk()->assertJsonPath('data.name', '高级商品运营专员');

        $this->withToken($token)->putJson('/api/v1/admin/roles/super_admin', [
            'name' => '超级管理员',
            'permission_codes' => ['rbac.manage'],
            'reason' => '尝试削弱超级管理员权限。',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('operation_logs', ['action' => 'admin_role.create', 'subject_id' => 'product_operator']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'admin_role.update', 'subject_id' => 'product_operator']);
        $this->assertSame('product_operator', $created->json('data.code'));
    }

    public function test_totp_enrollment_login_challenge_recovery_code_and_admin_reset(): void
    {
        [$admin, $token] = $this->admin('mfa-owner');
        [, $resetterToken] = $this->admin('mfa-resetter');
        $enrollment = $this->withToken($token)->postJson('/api/v1/admin/mfa/totp/enroll')->assertCreated();
        $secret = $enrollment->json('data.secret');
        $code = app(TotpService::class)->currentCode($secret);
        $confirmed = $this->withToken($token)->postJson('/api/v1/admin/mfa/totp/confirm', ['code' => $code])
            ->assertOk()->assertJsonCount(8, 'data.recovery_codes');
        $recoveryCode = $confirmed->json('data.recovery_codes.0');
        $this->withToken($token)->postJson('/api/v1/admin/auth/logout', ['all_sessions' => true])->assertNoContent();

        $challenge = $this->postJson('/api/v1/admin/auth/login', ['account' => 'mfa-owner', 'password' => 'AdminPassword12345!'])
            ->assertStatus(202)->assertJsonPath('data.mfa_required', true);
        $mfaLogin = $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'challenge_id' => $challenge->json('data.challenge_id'), 'challenge_token' => $challenge->json('data.challenge_token'), 'code' => app(TotpService::class)->currentCode($secret),
        ])->assertOk();
        $this->withToken($mfaLogin->json('data.access_token'))->getJson('/api/v1/admin/me')->assertOk();
        $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'challenge_id' => $challenge->json('data.challenge_id'), 'challenge_token' => $challenge->json('data.challenge_token'), 'code' => app(TotpService::class)->currentCode($secret),
        ])->assertForbidden()->assertJsonPath('code', 'ADMIN_MFA_CHALLENGE_INVALID');

        $recoveryChallenge = $this->postJson('/api/v1/admin/auth/login', ['account' => 'mfa-owner', 'password' => 'AdminPassword12345!'])->assertStatus(202);
        $this->postJson('/api/v1/admin/auth/mfa/verify', [
            'challenge_id' => $recoveryChallenge->json('data.challenge_id'), 'challenge_token' => $recoveryChallenge->json('data.challenge_token'), 'code' => $recoveryCode,
        ])->assertOk();

        $this->withToken($resetterToken)->postJson("/api/v1/admin/admin-users/{$admin->public_id}/reset-mfa", ['reason' => '用户验证身份后申请重置验证器。'])
            ->assertOk()->assertJsonPath('data.requires_mfa', false);
        $this->postJson('/api/v1/admin/auth/login', ['account' => 'mfa-owner', 'password' => 'AdminPassword12345!'])->assertOk();
    }

    /** @return array{AdminUser,string} */
    private function admin(string $account): array
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create(['account' => $account, 'display_name' => $account, 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', ['account' => $account, 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }
}
