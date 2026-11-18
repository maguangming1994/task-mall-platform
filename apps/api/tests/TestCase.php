<?php

namespace Tests;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use Database\Seeders\ReleaseControlSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 镜像生产 DatabaseSeeder：已上线模块全局开关默认 enabled，
        // 保证模块门禁“全局开关缺失即关闭”的口径在测试环境与生产一致；
        // 不使用数据库的单元测试（无 feature_flags 表）自动跳过。
        if (Schema::hasTable('feature_flags')) {
            $this->seed(ReleaseControlSeeder::class);
        }
    }

    /**
     * 将测试用户绑定到测试校区并补齐档案，满足 UGC 写接口的 campus.bound 门槛。
     */
    protected function bindTestCampus(User $user): void
    {
        $campus = Campus::query()->firstOrCreate(
            ['code' => 'test-campus'],
            [
                'name' => '测试大学',
                'status' => 'active',
                'catalog_type' => 'manual',
                'catalog_status' => 'manual',
            ],
        );
        $user->forceFill([
            'campus_id' => $campus->id,
            'grade_year' => $user->grade_year ?? 2025,
            'major' => $user->major ?? '计算机科学',
        ])->save();
        $user->refresh();
    }
}
