<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 资料分类（考研资料 / 考试资料 / 专业课资料 …）
        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 40);
            $table->string('icon', 80)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();
        });

        // 学习资料（网盘分享）
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('material_categories')->nullOnDelete();
            $table->foreignId('uploader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name', 40)->nullable();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('netdisk_type', 24)->default('other')->comment('baidu/quark/aliyun/other');
            $table->string('share_url', 500);
            $table->string('extract_code', 32)->nullable();
            $table->text('share_text')->nullable();
            $table->string('file_type', 16)->default('other')->comment('pdf/word/excel/ppt/video/archive/other');
            $table->string('source', 16)->default('user')->comment('user/admin');
            $table->string('status', 24)->default('pending')->index()->comment('pending/approved/rejected/offline');
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('bonus_granted_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('fetch_count')->default(0);
            $table->timestamps();
            $table->index(['campus_id', 'status']);
            $table->index(['status', 'category_id']);
            $table->index(['uploader_user_id', 'status']);
        });

        // 求资料需求
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->text('requirement');
            $table->string('status', 24)->default('pending')->index()->comment('pending/processing/done/closed');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        // 资料获取记录（同一用户重复获取同一资料不再扣额度）
        Schema::create('material_fetches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['material_id', 'user_id']);
        });

        // 用户资料获取额度（每日免费 + 投喂奖励的长期额度）
        Schema::create('material_user_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->date('daily_date')->nullable();
            $table->unsignedInteger('daily_used')->default(0);
            $table->unsignedInteger('bonus_quota')->default(0);
            $table->timestamps();
        });

        // 侵权举报
        Schema::create('material_reports', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 120);
            $table->text('detail')->nullable();
            $table->string('status', 24)->default('pending')->index()->comment('pending/handled');
            $table->foreignId('handled_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_reports');
        Schema::dropIfExists('material_user_quotas');
        Schema::dropIfExists('material_fetches');
        Schema::dropIfExists('material_requests');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('material_categories');
    }
};
