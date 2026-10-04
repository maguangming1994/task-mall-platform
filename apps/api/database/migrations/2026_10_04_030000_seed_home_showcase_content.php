<?php

use Database\Seeders\HomeShowcaseSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * 首页与任务大厅线上展示内容（7 个校园互助任务 + 首页装修）。
 *
 * 内容全部带固定幂等键，由 HomeShowcaseSeeder 写入；迁移只负责在发布期触发一次。
 * testing 环境跳过，避免用展示数据干扰测试断言。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        app(HomeShowcaseSeeder::class)->run();
    }

    public function down(): void
    {
        // 正式参考内容带固定幂等键，回滚迁移时不删除业务数据。
    }
};
