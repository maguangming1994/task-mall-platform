<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 校区代理交流帖：所有代理互相可见，按代理身份隔离（非普通学生）
        Schema::create('campus_agent_posts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('author_admin_id')->comment('发帖代理 admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->foreignId('author_campus_id')->nullable()->comment('代理所属校区（冗余方便展示）')->constrained('campuses')->nullOnDelete();
            $table->string('title', 140);
            $table->text('content');
            $table->string('category_code', 32)->default('notice')->index(); // notice 公告 / experience 经验 / question 求助
            $table->string('status', 24)->default('published')->index(); // published / hidden
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('author_admin_id');
        });

        // 小程序 openid ↔ 代理账号绑定表（一个 openid 对应一个代理）
        Schema::create('campus_agent_wechat_bindings', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('app_id', 64)->comment('小程序 app_id');
            $table->string('openid_hash', 64)->comment('openid SHA256 哈希（不存明文）')->index();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            $table->string('nickname_snapshot', 80)->nullable()->comment('绑定时的微信昵称快照');
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'openid_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_agent_wechat_bindings');
        Schema::dropIfExists('campus_agent_posts');
    }
};
