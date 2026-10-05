<?php

namespace Tests;

use Database\Seeders\ReleaseControlSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 镜像生产 DatabaseSeeder：13 个模块全局开关默认 enabled，
        // 保证模块门禁“全局开关缺失即关闭”的口径在测试环境与生产一致；
        // 不使用数据库的单元测试（无 feature_flags 表）自动跳过。
        if (Schema::hasTable('feature_flags')) {
            $this->seed(ReleaseControlSeeder::class);
        }
    }
}
