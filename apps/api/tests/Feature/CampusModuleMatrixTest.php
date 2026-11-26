<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Models\FeatureFlag;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusModuleMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function campus(string $code = 'matrix-campus'): Campus
    {
        return Campus::query()->create(['code' => $code, 'name' => '矩阵学校', 'status' => 'active']);
    }

    private function superAdmin(string $account = 'matrix-root'): array
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => '平台超管',
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    private function campusAgent(Campus $campus, string $account = 'matrix-agent'): array
    {
        $this->seed(RbacSeeder::class);
        $agent = AdminUser::query()->create([
            'account' => $account,
            'display_name' => '学校代理',
            'password' => 'AgentPassword12345!',
        ]);
        $agent->roles()->attach(Role::query()->where('code', 'campus_agent')->value('id'));
        $agent->campuses()->attach($campus->id, ['is_primary' => true]);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AgentPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$agent, $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleMap(string $token, Campus $campus): array
    {
        $response = $this->withToken($token)->getJson("/api/v1/admin/campuses/{$campus->public_id}/modules")->assertOk();
        $modules = [];
        foreach ($response->json('data.modules') as $row) {
            $modules[$row['code']] = $row;
        }

        return $modules;
    }

    public function test_matrix_returns_21_modules_groups_and_three_packages(): void
    {
        [, $token] = $this->superAdmin();
        $campus = $this->campus();

        $response = $this->withToken($token)->getJson("/api/v1/admin/campuses/{$campus->public_id}/modules")
            ->assertOk()
            ->assertJsonPath('data.campus.id', $campus->public_id)
            ->assertJsonCount(21, 'data.modules')
            ->assertJsonCount(3, 'data.packages');

        $modules = collect($response->json('data.modules'))->keyBy('code');
        // 无覆盖行：全部 inherit，全局 enabled 下最终生效为开
        $this->assertSame('inherit', $modules['module_second_hand']['status']);
        $this->assertSame(0, $modules['module_second_hand']['version']);
        $this->assertTrue($modules['module_second_hand']['effective']);
        $this->assertSame('enabled', $modules['module_second_hand']['global']['mode']);

        $packageCodes = collect($response->json('data.packages'))->pluck('code')->all();
        $this->assertSame(['basic', 'standard', 'full'], $packageCodes);
    }

    public function test_single_module_lifecycle_optimistic_lock_audit_and_capability_sync(): void
    {
        [, $token] = $this->superAdmin();
        $campus = $this->campus();
        $uri = "/api/v1/admin/campuses/{$campus->public_id}/modules/module_second_hand";

        // 首次关闭：expected_version=0
        $this->withToken($token)->putJson($uri, ['status' => 'disabled', 'expected_version' => 0, 'reason' => '本期暂不开放闲置。'])
            ->assertOk()
            ->assertJsonPath('data.modules.12.code', 'module_second_hand');

        $map = $this->moduleMap($token, $campus);
        $this->assertSame('disabled', $map['module_second_hand']['status']);
        $this->assertSame(1, $map['module_second_hand']['version']);
        $this->assertFalse($map['module_second_hand']['effective']);

        // capabilities 立即跟随（缓存已失效）；匿名 + X-Campus-Id，先清理超管默认头
        $this->flushHeaders();
        $this->getJson('/api/v1/client-capabilities', [
            'X-Client-Platform' => 'wechat-mini',
            'X-Device-Id' => 'matrix-device',
            'X-Campus-Id' => $campus->public_id,
        ])->assertJsonPath('data.feature_flags.module_second_hand', false);

        // 旧版本号再写 → 422 冲突
        $this->withToken($token)->putJson($uri, ['status' => 'disabled', 'expected_version' => 0])
            ->assertStatus(422);

        // 非法模块 code → 422
        $this->withToken($token)->putJson(
            "/api/v1/admin/campuses/{$campus->public_id}/modules/not_a_module",
            ['status' => 'enabled', 'expected_version' => 0],
        )->assertStatus(422);

        // 开启：版本号递增到 2
        $this->withToken($token)->putJson($uri, ['status' => 'enabled', 'expected_version' => 1])
            ->assertOk();
        $map = $this->moduleMap($token, $campus);
        $this->assertSame('enabled', $map['module_second_hand']['status']);
        $this->assertSame(2, $map['module_second_hand']['version']);
        $this->assertTrue($map['module_second_hand']['effective']);

        // 回到继承：覆盖行删除
        $this->withToken($token)->putJson($uri, ['status' => 'inherit', 'expected_version' => 2])
            ->assertOk();
        $this->assertDatabaseMissing('campus_feature_flags', [
            'campus_id' => $campus->id,
            'code' => 'module_second_hand',
        ]);
        $map = $this->moduleMap($token, $campus);
        $this->assertSame('inherit', $map['module_second_hand']['status']);
        $this->assertTrue($map['module_second_hand']['effective']);

        // 审计落库
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'campus_module.updated',
            'subject_id' => $campus->public_id.':module_second_hand',
        ]);
    }

    public function test_packages_standard_then_full_match_definitions_and_audit(): void
    {
        [, $token] = $this->superAdmin();
        $campus = $this->campus();
        $uri = "/api/v1/admin/campuses/{$campus->public_id}/module-packages";

        // 非法套餐 → 422
        $this->withToken($token)->postJson($uri.'/ultimate')->assertStatus(422);

        $this->withToken($token)->postJson($uri.'/standard', ['reason' => '新校区按标准版分期上线。'])
            ->assertOk()
            ->assertJsonPath('data.campus.id', $campus->public_id);

        // 21 行显式覆盖；套餐内开、套餐外（含子模块）关
        $this->assertSame(21, CampusFeatureFlag::query()->where('campus_id', $campus->id)->count());
        $map = $this->moduleMap($token, $campus);
        $this->assertTrue($map['module_second_hand']['effective']);
        $this->assertTrue($map['module_mall']['effective']);
        $this->assertFalse($map['module_mall_trade']['effective']);
        $this->assertFalse($map['module_after_sale']['effective']);
        $this->assertFalse($map['module_task_publish']['effective']);
        $this->assertFalse($map['module_community_post']['effective']);
        $this->assertFalse($map['module_wallet']['effective']);
        $this->assertFalse($map['module_withdraw']['effective']);
        $this->assertFalse($map['module_referrals']['effective']);
        // 基础版三模块保持开放
        $this->assertTrue($map['module_tasks']['effective']);
        $this->assertTrue($map['module_community']['effective']);
        $this->assertTrue($map['module_checkin']['effective']);

        // 完整版：清空覆盖行，全部跟随全局
        $this->withToken($token)->postJson($uri.'/full')->assertOk();
        $this->assertSame(0, CampusFeatureFlag::query()->where('campus_id', $campus->id)->count());
        $map = $this->moduleMap($token, $campus);
        $this->assertTrue($map['module_mall_trade']['effective']);
        $this->assertTrue($map['module_wallet_recharge']['effective']);

        $this->assertDatabaseHas('operation_logs', [
            'action' => 'campus_module_package.applied',
            'subject_id' => $campus->public_id.':standard',
        ]);
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'campus_module_package.applied',
            'subject_id' => $campus->public_id.':full',
        ]);
    }

    public function test_agent_can_read_matrix_but_cannot_write_and_is_campus_scoped(): void
    {
        $campus = $this->campus('agent-campus');
        $other = Campus::query()->create(['code' => 'other-campus', 'name' => '别的学校', 'status' => 'active']);
        [, $agentToken] = $this->campusAgent($campus);

        // 只读：已分配学校的矩阵可看
        $this->withToken($agentToken)->getJson("/api/v1/admin/campuses/{$campus->public_id}/modules")
            ->assertOk()->assertJsonCount(21, 'data.modules');

        // 写入 403（代理无 campus.module_manage）
        $this->withToken($agentToken)->putJson(
            "/api/v1/admin/campuses/{$campus->public_id}/modules/module_second_hand",
            ['status' => 'disabled', 'expected_version' => 0],
        )->assertForbidden();
        $this->withToken($agentToken)->postJson(
            "/api/v1/admin/campuses/{$campus->public_id}/module-packages/basic",
        )->assertForbidden();

        // 未分配学校 403
        $this->withToken($agentToken)->getJson("/api/v1/admin/campuses/{$other->public_id}/modules")
            ->assertForbidden()->assertJsonPath('code', 'CAMPUS_SCOPE_DENIED');
    }

    public function test_writes_require_recent_verification(): void
    {
        [, $token] = $this->superAdmin('stale-matrix-root');
        $campus = $this->campus('stale-campus');
        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);

        $this->withToken($token)->putJson(
            "/api/v1/admin/campuses/{$campus->public_id}/modules/module_checkin",
            ['status' => 'disabled', 'expected_version' => 0],
        )->assertForbidden()->assertJsonPath('code', 'ADMIN_RECENT_VERIFICATION_REQUIRED');

        // 二次验证后放行
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])
            ->assertOk();
        $this->withToken($token)->putJson(
            "/api/v1/admin/campuses/{$campus->public_id}/modules/module_checkin",
            ['status' => 'disabled', 'expected_version' => 0],
        )->assertOk();
    }

    public function test_global_master_switch_closes_module_even_when_campus_enables_it(): void
    {
        [, $token] = $this->superAdmin();
        $campus = $this->campus();
        FeatureFlag::query()->where('code', 'module_checkin')->update(['mode' => 'disabled', 'rollout_percentage' => 0]);

        $this->withToken($token)->putJson(
            "/api/v1/admin/campuses/{$campus->public_id}/modules/module_checkin",
            ['status' => 'enabled', 'expected_version' => 0],
        )->assertOk();

        $map = $this->moduleMap($token, $campus);
        $this->assertFalse($map['module_checkin']['effective']);
    }
}
