<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // campuses：隔离模式（legacy=旧宽松，strict=新严格）
        Schema::table('campuses', function (Blueprint $table) {
            $table->string('isolation_mode', 16)->default('legacy')->after('status');
        });

        // tasks：平台投放标记（跨校可见的唯一合法例外）
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('is_platform_wide')->default(false)->after('campus_id');
            $table->index(['is_platform_wide', 'campus_id']);
        });

        // 闲置订单/IM 会话：冗余校区归属，便于后台按校检索与风控
        Schema::table('second_hand_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('campus_id')->nullable()->after('id');
            $table->index('campus_id');
        });
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('campus_id')->nullable()->after('id');
            $table->index('campus_id');
        });

        // users：校园档案三字段（与校区解耦，换校不丢失）
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('grade_year')->nullable()->after('campus_id'); // 入学年份，如 2025
            $table->string('major', 30)->nullable()->after('grade_year');               // 专业名称，手动填写
            $table->string('college', 60)->nullable()->after('major');                  // 学院名称，选填
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['grade_year', 'major', 'college']);
        });
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex(['campus_id']);
            $table->dropColumn('campus_id');
        });
        Schema::table('second_hand_orders', function (Blueprint $table) {
            $table->dropIndex(['campus_id']);
            $table->dropColumn('campus_id');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['is_platform_wide', 'campus_id']);
            $table->dropColumn('is_platform_wide');
        });
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropColumn('isolation_mode');
        });
    }
};
