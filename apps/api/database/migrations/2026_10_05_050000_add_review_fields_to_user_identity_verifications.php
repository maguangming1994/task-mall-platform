<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 实名认证人工审核留痕：审核管理员、审核时间、审核备注。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_identity_verifications', function (Blueprint $table) {
            $table->foreignId('reviewed_by_admin_id')->nullable()->after('provider_request_id')
                ->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable()->after('reviewed_by_admin_id');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');
            // 便于后台/移动工作台按状态+时间检索待审核记录
            $table->index(['status', 'id'], 'user_identity_verifications_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_identity_verifications', function (Blueprint $table) {
            $table->dropIndex('user_identity_verifications_status_id_index');
            $table->dropConstrainedForeignId('reviewed_by_admin_id');
            $table->dropColumn(['reviewed_at', 'review_note']);
        });
    }
};
