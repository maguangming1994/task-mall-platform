<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserCenterFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_operations_center_uses_masked_identity_and_safe_growth_rules(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'user-operations-admin',
            'display_name' => '用户运营管理员',
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'platform_admin')->value('id'));
        User::factory()->create([
            'nickname' => '运营中心样例用户',
            'phone' => '13800138088',
            'phone_verified_at' => now(),
            'profile_completed_at' => now(),
            'status' => 'active',
        ]);
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'user-operations-admin',
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/admin/user-center/overview')
            ->assertOk()
            ->assertJsonPath('data.policies.real_name_collection_enabled', false)
            ->assertJsonPath('data.policies.self_registration_enabled', true)
            ->assertJsonCount(4, 'data.level_rules');

        $this->withToken($token)->getJson('/api/v1/admin/user-center/users')
            ->assertOk()
            ->assertJsonPath('data.privacy.phone_masked_by_default', true)
            ->assertJsonPath('data.privacy.bulk_export_enabled', false)
            ->assertJsonPath('data.items.0.phone_masked', '138****8088');

        $this->withToken($token)->getJson('/api/v1/admin/user-center/verifications')
            ->assertOk()
            ->assertJsonPath('data.identity_policy.collect_id_number', false)
            ->assertJsonPath('data.identity_policy.collect_id_images', false)
            ->assertJsonPath('data.items.0.real_name_status', 'not_collected');
    }
}
