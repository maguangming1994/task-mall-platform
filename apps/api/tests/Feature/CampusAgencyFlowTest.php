<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CampusAgencyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_campus_list_only_returns_active_schools_in_configured_order(): void
    {
        $campusB = Campus::query()->create(['code' => 'campus-b', 'name' => '第二学校', 'status' => 'active', 'sort_order' => 20]);
        Campus::query()->create(['code' => 'campus-a', 'name' => '第一学校', 'status' => 'active', 'sort_order' => 10]);
        Campus::query()->create(['code' => 'campus-off', 'name' => '停用学校', 'status' => 'disabled', 'sort_order' => 1]);

        $this->getJson('/api/v1/campuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'campus-a')
            ->assertJsonPath('data.items.1.code', 'campus-b')
            ->assertJsonCount(2, 'data.items');

        $this->getJson('/api/v1/campuses?keyword=campus-b')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'campus-b');

        $this->getJson('/api/v1/campuses?keyword='.$campusB->public_id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $campusB->public_id);

        foreach (range(1, 12) as $index) {
            Campus::query()->create([
                'code' => 'campus-b-related-'.$index,
                'name' => '相似学校'.$index,
                'status' => 'active',
                'sort_order' => 1,
            ]);
        }
        $this->getJson('/api/v1/campuses?keyword=campus-b&limit=10')
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.items.0.code', 'campus-b');
    }

    public function test_public_campus_list_uses_precise_then_city_locations_and_sorts_unavailable_last(): void
    {
        Campus::query()->create([
            'code' => 'precise-campus',
            'name' => '精确坐标学校',
            'city' => '北京市',
            'latitude' => 28.6817,
            'longitude' => 115.8579,
            'status' => 'active',
            'sort_order' => 30,
        ]);
        Campus::query()->create([
            'code' => 'city-campus',
            'name' => '城市估算学校',
            'city' => '南昌市',
            'status' => 'active',
            'sort_order' => 10,
        ]);
        Campus::query()->create([
            'code' => 'unknown-campus',
            'name' => '位置未知学校',
            'city' => '不存在的城市',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $response = $this->postJson('/api/v1/campuses/nearby', [
            'latitude' => 28.6817,
            'longitude' => 115.8579,
            'limit' => 100,
        ])
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.code', 'precise-campus')
            ->assertJsonPath('data.items.0.location_accuracy', 'campus')
            ->assertJsonPath('data.items.0.latitude', 28.6817)
            ->assertJsonPath('data.items.0.longitude', 115.8579)
            ->assertJsonPath('data.items.0.distance_km', 0)
            ->assertJsonPath('data.items.2.code', 'unknown-campus')
            ->assertJsonPath('data.items.2.location_accuracy', 'unavailable')
            ->assertJsonPath('data.items.2.latitude', null)
            ->assertJsonPath('data.items.2.longitude', null)
            ->assertJsonPath('data.items.2.distance_km', null);

        $city = collect($response->json('data.items'))->firstWhere('code', 'city-campus');
        $this->assertSame('city', $city['location_accuracy']);
        $this->assertSame(28.68396, $city['latitude']);
        $this->assertSame(115.85306, $city['longitude']);
        $this->assertNull($city['distance_km']);
    }

    public function test_nearby_campus_list_requires_valid_latitude_and_longitude(): void
    {
        $this->postJson('/api/v1/campuses/nearby', ['latitude' => 28.68])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['longitude']]]);

        $this->postJson('/api/v1/campuses/nearby', ['longitude' => 115.85])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['errors' => ['latitude']]]);

        $this->postJson('/api/v1/campuses/nearby', ['latitude' => 91, 'longitude' => 115.85])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_trusted_proxy_chain_uses_the_real_client_ip_without_trusting_public_callers(): void
    {
        Route::get('/api/v1/testing/campus-client-address', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.5'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.10',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/api/v1/testing/campus-client-address')
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.10')
            ->assertJsonPath('secure', true);

        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.99',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('/api/v1/testing/campus-client-address')
            ->assertOk()
            ->assertJsonPath('ip', '198.51.100.20');
    }

    public function test_campus_limiters_are_isolated_by_endpoint_device_and_trusted_client_ip(): void
    {
        Campus::query()->create(['code' => 'limiter-campus', 'name' => '限流测试学校', 'status' => 'active']);
        $proxy = ['REMOTE_ADDR' => '172.20.0.6'];
        $primary = [
            'X-Device-Id' => 'campus-device-primary',
            'X-Forwarded-For' => '203.0.113.20',
        ];

        foreach (range(1, 60) as $_) {
            $this->withServerVariables($proxy)->withHeaders($primary)
                ->getJson('/api/v1/campuses')
                ->assertOk();
        }

        $this->withServerVariables($proxy)->withHeaders($primary)
            ->getJson('/api/v1/campuses')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'RATE_LIMITED');

        $this->withServerVariables($proxy)->withHeaders($primary)
            ->postJson('/api/v1/campuses/nearby', ['latitude' => 28.68, 'longitude' => 115.85])
            ->assertOk();

        $this->withServerVariables($proxy)->withHeaders([
            ...$primary,
            'X-Device-Id' => 'campus-device-secondary',
        ])->getJson('/api/v1/campuses')->assertOk();

        $this->withServerVariables($proxy)->withHeaders([
            ...$primary,
            'X-Forwarded-For' => '203.0.113.21',
        ])->getJson('/api/v1/campuses')->assertOk();
    }

    public function test_super_admin_can_create_school_and_assign_agent(): void
    {
        $this->seed(RbacSeeder::class);
        $root = $this->createAdmin('campus-root', 'super_admin');
        $agent = $this->createAdmin('campus-agent-one', 'campus_agent');
        $token = $this->login($root);

        $campusId = $this->withToken($token)->postJson('/api/v1/admin/campuses', [
            'code' => 'jx-campus',
            'name' => '江西示范学校',
            'province' => '江西省',
            'city' => '南昌市',
            'status' => 'active',
            'sort_order' => 10,
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/v1/admin/campuses/{$campusId}/agents", [
            'admin_user_ids' => [$agent->public_id],
            'primary_admin_user_id' => $agent->public_id,
        ])->assertOk()
            ->assertJsonPath('data.agents.0.id', $agent->public_id)
            ->assertJsonPath('data.agents.0.is_primary', true);

        $this->assertDatabaseHas('admin_user_campus', [
            'admin_user_id' => $agent->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('operation_logs', ['action' => 'campus.agents_update']);
    }

    public function test_school_agent_cannot_switch_request_to_an_unassigned_school(): void
    {
        $this->seed(RbacSeeder::class);
        $agent = $this->createAdmin('campus-agent-two', 'campus_agent');
        $assigned = Campus::query()->create(['code' => 'assigned', 'name' => '已分配学校', 'status' => 'active']);
        $other = Campus::query()->create(['code' => 'other', 'name' => '其他学校', 'status' => 'active']);
        $agent->campuses()->attach($assigned->id, ['is_primary' => true]);
        $token = $this->login($agent);

        $this->withToken($token)
            ->withHeader('X-Campus-Id', $other->public_id)
            ->getJson('/api/v1/admin/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'CAMPUS_SCOPE_DENIED');

        $this->withToken($token)
            ->withHeader('X-Campus-Id', $assigned->public_id)
            ->getJson('/api/v1/admin/me')
            ->assertOk();
    }

    public function test_school_agent_only_sees_assigned_school_and_users(): void
    {
        $this->seed(RbacSeeder::class);
        $agent = $this->createAdmin('campus-agent-scoped', 'campus_agent');
        $assigned = Campus::query()->create(['code' => 'scoped', 'name' => '代理学校', 'status' => 'active']);
        $other = Campus::query()->create(['code' => 'outside', 'name' => '其他学校', 'status' => 'active']);
        $agent->campuses()->attach($assigned->id, ['is_primary' => true]);
        $assignedUser = User::factory()->create(['campus_id' => $assigned->id, 'nickname' => '本校用户']);
        User::factory()->create(['campus_id' => $other->id, 'nickname' => '外校用户']);
        $token = $this->login($agent);

        $this->withToken($token)
            ->getJson('/api/v1/admin/campuses')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $assigned->public_id);

        $this->withToken($token)
            ->getJson('/api/v1/admin/user-center/users')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $assignedUser->public_id);

        $this->withToken($token)
            ->postJson('/api/v1/admin/campuses', [
                'code' => 'forbidden-campus',
                'name' => '不可创建学校',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_only_super_admin_can_switch_campus_isolation_mode(): void
    {
        $this->seed(RbacSeeder::class);
        $agent = $this->createAdmin('campus-isolation-agent', 'campus_agent');
        $root = $this->createAdmin('campus-isolation-root', 'super_admin');
        $campus = Campus::query()->create(['code' => 'isolation-campus', 'name' => '隔离学校', 'status' => 'active']);
        $agent->campuses()->attach($campus->id, ['is_primary' => true]);

        $this->withToken($this->login($agent))
            ->withHeader('X-Campus-Id', $campus->public_id)
            ->putJson('/api/v1/admin/campuses/'.$campus->public_id, [
                'code' => $campus->code,
                'name' => $campus->name,
                'status' => 'active',
                'isolation_mode' => 'strict',
            ])->assertForbidden()->assertJsonPath('code', 'CAMPUS_ISOLATION_FORBIDDEN');

        $this->assertSame('legacy', $campus->fresh()->isolation_mode);

        $this->withToken($this->login($root))
            ->putJson('/api/v1/admin/campuses/'.$campus->public_id, [
                'code' => $campus->code,
                'name' => $campus->name,
                'status' => 'active',
                'isolation_mode' => 'strict',
            ])->assertOk()->assertJsonPath('data.isolation_mode', 'strict');
    }

    public function test_only_active_campus_agent_accounts_can_be_assigned_and_primary_must_be_selected(): void
    {
        $this->seed(RbacSeeder::class);
        $root = $this->createAdmin('campus-role-root', 'super_admin');
        $agent = $this->createAdmin('valid-campus-agent', 'campus_agent');
        $ordinaryAdmin = $this->createAdmin('ordinary-operator', 'operations_admin');
        $campus = Campus::query()->create(['code' => 'role-check-campus', 'name' => '角色校验学校', 'status' => 'disabled']);
        $token = $this->login($root);

        $this->withToken($token)->getJson('/api/v1/admin/campus-agent-options')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $agent->public_id);

        $this->withToken($token)->putJson("/api/v1/admin/campuses/{$campus->public_id}/agents", [
            'admin_user_ids' => [$ordinaryAdmin->public_id],
            'primary_admin_user_id' => $ordinaryAdmin->public_id,
        ])->assertUnprocessable()
            ->assertJsonPath('details.errors.admin_user_ids.0', '只能分配状态正常且具有“学校代理”角色的管理员账号。');

        $this->withToken($token)->putJson("/api/v1/admin/campuses/{$campus->public_id}/agents", [
            'admin_user_ids' => [],
            'primary_admin_user_id' => $agent->public_id,
        ])->assertUnprocessable()
            ->assertJsonPath('details.errors.primary_admin_user_id.0', '主代理必须包含在已选代理账号中。');
    }

    public function test_admin_campus_library_supports_server_side_filters_pagination_and_agent_overview(): void
    {
        $this->seed(RbacSeeder::class);
        $root = $this->createAdmin('campus-list-root', 'super_admin');
        $agent = $this->createAdmin('campus-list-agent', 'campus_agent');
        $target = Campus::query()->create([
            'code' => 'target-university',
            'official_school_code' => '4111000000',
            'name' => '目标大学',
            'catalog_type' => 'ordinary',
            'education_level' => '本科',
            'catalog_status' => 'listed',
            'province' => '北京市',
            'city' => '北京市',
            'status' => 'active',
        ]);
        Campus::query()->create(['code' => 'other-college', 'name' => '其他学院', 'catalog_type' => 'manual', 'catalog_status' => 'manual', 'province' => '河北省', 'status' => 'disabled']);
        $target->agents()->attach($agent->id, ['is_primary' => true]);
        User::factory()->create(['campus_id' => $target->id]);
        $token = $this->login($root);

        $this->withToken($token)->getJson('/api/v1/admin/campuses?province='.urlencode('北京市').'&education_level='.urlencode('本科').'&status=active&page_size=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->public_id)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.active', 1)
            ->assertJsonPath('data.summary.without_primary_agent', 0)
            ->assertJsonPath('data.summary.agent_total', 1)
            ->assertJsonPath('data.summary.user_total', 1);

        $this->withToken($token)->getJson('/api/v1/admin/campus-agents')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $agent->public_id)
            ->assertJsonPath('data.items.0.campuses.0.id', $target->public_id)
            ->assertJsonPath('data.items.0.covered_users_count', 1)
            ->assertJsonPath('data.summary.campuses_covered', 1)
            ->assertJsonPath('data.summary.users_covered', 1);
    }

    public function test_super_admin_with_an_additional_agent_role_keeps_global_scope(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = $this->createAdmin('mixed-role-root', 'super_admin');
        $admin->roles()->attach(Role::query()->where('code', 'campus_agent')->value('id'));
        Campus::query()->create(['code' => 'global-campus', 'name' => '全局学校', 'status' => 'active']);
        $token = $this->login($admin);

        $this->withToken($token)->getJson('/api/v1/admin/campuses')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    private function createAdmin(string $account, string $role): AdminUser
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $role)->value('id'));

        return $admin;
    }

    private function login(AdminUser $admin): string
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'StrongPassword123!',
        ])->assertOk()->json('data.access_token');
    }
}
