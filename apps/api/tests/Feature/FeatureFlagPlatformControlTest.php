<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Models\FeatureFlag;
use App\Modules\Operations\Services\ModuleGateService;
use App\Modules\Operations\Services\ReleaseControlService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureFlagPlatformControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_h5_is_fully_open_while_wechat_mini_keeps_global_state(): void
    {
        $gates = app(ModuleGateService::class);

        // 未上线模块：全局关闭，但 H5 端默认为测试全量开放；小程序仍保持关闭。
        $this->assertTrue($gates->effective('module_material', null, 'device-a', 'h5-wechat'));
        $this->assertTrue($gates->effective('module_material', null, 'device-a', 'h5-browser'));
        $this->assertFalse($gates->effective('module_material', null, 'device-a', 'wechat-mini'));

        // 已上线模块：两端都保持开启。
        $this->assertTrue($gates->effective('module_tasks', null, 'device-a', 'h5-wechat'));
        $this->assertTrue($gates->effective('module_tasks', null, 'device-a', 'wechat-mini'));
    }

    public function test_campus_disable_still_blocks_h5_even_when_platform_enabled(): void
    {
        $campus = Campus::query()->create(['code' => 'platform-campus-a', 'name' => '按端测试校', 'status' => 'active']);
        CampusFeatureFlag::query()->create(['campus_id' => $campus->id, 'code' => 'module_tasks', 'status' => 'disabled']);

        $gates = app(ModuleGateService::class);
        $this->assertFalse($gates->effective('module_tasks', $campus->id, 'device-b', 'h5-wechat'));
        $this->assertFalse($gates->effective('module_tasks', $campus->id, 'device-b', 'wechat-mini'));
    }

    public function test_explicit_h5_disable_keeps_wechat_mini_untouched(): void
    {
        FeatureFlag::query()->where('code', 'module_tasks')->firstOrFail()->update([
            'platform_modes' => ['wechat-mini' => 'inherit', 'h5' => 'disabled'],
        ]);

        $gates = app(ModuleGateService::class);
        $this->assertFalse($gates->effective('module_tasks', null, 'device-c', 'h5-wechat'));
        $this->assertTrue($gates->effective('module_tasks', null, 'device-c', 'wechat-mini'));
    }

    public function test_capabilities_payload_differs_by_client_platform(): void
    {
        $headers = ['X-Device-Id' => 'cap-platform-device'];
        $h5 = $this->getJson('/api/v1/client-capabilities', $headers + ['X-Client-Platform' => 'h5-wechat'])
            ->assertOk()->json('data.feature_flags');
        $mini = $this->getJson('/api/v1/client-capabilities', $headers + ['X-Client-Platform' => 'wechat-mini'])
            ->assertOk()->json('data.feature_flags');

        $this->assertTrue($h5['module_material']);
        $this->assertFalse($mini['module_material']);
        $this->assertTrue($h5['module_tasks']);
        $this->assertTrue($mini['module_tasks']);
    }

    public function test_admin_can_update_platform_modes_with_version_history(): void
    {
        $admin = AdminUser::query()->create([
            'account' => 'platform-admin',
            'display_name' => '按端配置管理员',
            'password' => 'AdminPassword12345!',
            'last_verified_at' => now(),
        ]);
        $flag = FeatureFlag::query()->where('code', 'module_tasks')->firstOrFail();
        $version = $flag->version;

        $updated = app(ReleaseControlService::class)->updateFlag(
            $admin,
            $flag,
            'enabled',
            100,
            [],
            $version,
            'H5 端关闭任务大厅，小程序保持不变。',
            null,
            ['wechat-mini' => 'inherit', 'h5' => 'disabled'],
        );

        $this->assertSame(['wechat-mini' => 'inherit', 'h5' => 'disabled'], $updated->platform_modes);
        $this->assertSame($version + 1, $updated->version);
        $this->assertDatabaseHas('feature_flag_versions', [
            'feature_flag_id' => $flag->id,
            'version' => $version + 1,
        ]);

        $gates = app(ModuleGateService::class);
        $this->assertFalse($gates->effective('module_tasks', null, 'device-d', 'h5-wechat'));
        $this->assertTrue($gates->effective('module_tasks', null, 'device-d', 'wechat-mini'));
    }
}
