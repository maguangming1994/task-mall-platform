<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->json('platform_modes')->nullable();
        });
        Schema::table('feature_flag_versions', function (Blueprint $table): void {
            $table->json('platform_modes')->nullable();
        });

        // H5 端默认全量开放，便于验收；小程序保持原有全局开关状态不变。
        // 提现涉及资金合规，保持跟随全局（生产环境仍由 Seeder 永久关闭）。
        DB::table('feature_flags')
            ->whereNull('platform_modes')
            ->where(function ($query): void {
                $query->where(function ($modules): void {
                    $modules->where('code', 'like', 'module_%')->where('code', '!=', 'module_withdraw');
                })->orWhereIn('code', ['checkout_v2', 'community_video', 'external_notifications']);
            })
            ->update(['platform_modes' => json_encode(['h5' => 'enabled'], JSON_UNESCAPED_UNICODE)]);
    }

    public function down(): void
    {
        Schema::table('feature_flag_versions', function (Blueprint $table): void {
            $table->dropColumn('platform_modes');
        });
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->dropColumn('platform_modes');
        });
    }
};
