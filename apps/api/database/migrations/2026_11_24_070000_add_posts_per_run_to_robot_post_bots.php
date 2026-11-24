<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_post_bots', function (Blueprint $table): void {
            // 每次调度（每分钟一次）最多发布的帖子条数，用于压测转发链路持续性。
            $table->unsignedSmallInteger('posts_per_run')->default(1)->after('posts_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('robot_post_bots', function (Blueprint $table): void {
            $table->dropColumn('posts_per_run');
        });
    }
};
