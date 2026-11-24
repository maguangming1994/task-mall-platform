<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_bot')->default(false)->after('status');
        });

        Schema::create('robot_post_bots', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 100);
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('campus_id');
            $table->string('category_code', 32)->default('experience');
            // pool：人工帖子池；history：复用历史帖子；ai：大模型生成
            $table->string('content_source', 16)->default('pool');
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->unsignedSmallInteger('posts_per_day')->default(3);
            $table->json('active_weekdays')->nullable();
            $table->string('active_start', 5)->default('09:00');
            $table->string('active_end', 5)->default('22:00');
            $table->unsignedSmallInteger('min_gap_minutes')->default(60);
            $table->unsignedSmallInteger('history_min_likes')->default(0);
            $table->text('ai_prompt')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->date('posted_date')->nullable();
            $table->unsignedSmallInteger('posted_today')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['enabled', 'campus_id']);
        });

        Schema::create('robot_post_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('robot_post_bot_id');
            $table->string('title', 200);
            $table->text('content');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['robot_post_bot_id', 'enabled', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_post_templates');
        Schema::dropIfExists('robot_post_bots');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_bot');
        });
    }
};
