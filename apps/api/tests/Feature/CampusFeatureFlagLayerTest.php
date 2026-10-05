<?php

namespace Tests\Feature;

use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CampusFeatureFlagLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_is_created_with_unique_campus_code_index(): void
    {
        $this->assertTrue(Schema::hasTable('campus_feature_flags'));
        $this->assertTrue(Schema::hasColumns('campus_feature_flags', [
            'id', 'campus_id', 'code', 'status', 'version', 'updated_by_admin_id', 'created_at', 'updated_at',
        ]));

        $campus = Campus::query()->create(['code' => 'uni-campus', 'name' => '唯一索引学校', 'status' => 'active']);
        CampusFeatureFlag::query()->create(['campus_id' => $campus->id, 'code' => 'module_mall']);

        $this->expectException(QueryException::class);
        CampusFeatureFlag::query()->create(['campus_id' => $campus->id, 'code' => 'module_mall']);
    }

    public function test_repeated_migration_does_not_error(): void
    {
        // RefreshDatabase 已执行过全部迁移；再次执行应因 migrations 表记录而幂等跳过
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    public function test_override_row_defaults_to_inherit_version_one(): void
    {
        $campus = Campus::query()->create(['code' => 'default-campus', 'name' => '默认值学校', 'status' => 'active']);

        $flag = CampusFeatureFlag::query()->create(['campus_id' => $campus->id, 'code' => 'module_tasks']);

        $this->assertSame('inherit', $flag->status);
        $this->assertSame(1, $flag->version);
        $this->assertTrue($campus->featureFlags->isNotEmpty());
    }

    public function test_override_rows_are_deleted_with_campus(): void
    {
        $campus = Campus::query()->create(['code' => 'cascade-campus', 'name' => '级联学校', 'status' => 'active']);
        CampusFeatureFlag::query()->create(['campus_id' => $campus->id, 'code' => 'module_tasks', 'status' => 'enabled']);

        $campus->delete();

        $this->assertSame(0, CampusFeatureFlag::query()->where('campus_id', $campus->id)->count());
    }

    public function test_no_override_rows_are_seeded_for_existing_campuses(): void
    {
        // AC-8：无覆盖行 = inherit，升级后行为与全局 enabled 一致
        Campus::query()->create(['code' => 'bare-campus', 'name' => '无覆盖学校', 'status' => 'active']);

        $this->assertSame(0, CampusFeatureFlag::query()->count());
    }
}
