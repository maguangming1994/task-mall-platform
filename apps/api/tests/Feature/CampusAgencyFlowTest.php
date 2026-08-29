<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Campus\Models\Campus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusAgencyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_campus_list_only_returns_active_schools_in_configured_order(): void
    {
        Campus::query()->create(['code' => 'campus-b', 'name' => '第二学校', 'status' => 'active', 'sort_order' => 20]);
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
