<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_posts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title', 120);
            $table->text('content');
            $table->string('status', 24)->index();
            $table->unsignedInteger('current_version_no')->default(1);
            $table->unsignedInteger('published_version_no')->nullable();
            $table->unsignedInteger('comment_count')->default(0);
            $table->boolean('reward_granted')->default(false);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_reason_code', 64)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('published_at')->nullable()->index();
            $table->softDeletesTz();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
        });
        Schema::create('community_post_versions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('community_post_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('title', 120);
            $table->text('content');
            $table->string('review_status', 24)->index();
            $table->json('rule_snapshot');
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_reason_code', 64)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['community_post_id', 'version_no']);
        });
        Schema::create('community_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_post_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_file_id')->constrained()->restrictOnDelete();
            $table->string('file_type', 16);
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->unique(['community_post_version_id', 'uploaded_file_id']);
        });
        Schema::create('community_comments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('community_post_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_comment_id')->nullable()->constrained('community_comments')->restrictOnDelete();
            $table->text('content');
            $table->string('status', 24)->index();
            $table->boolean('reward_granted')->default(false);
            $table->json('rule_snapshot');
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_reason_code', 64)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('published_at')->nullable()->index();
            $table->softDeletesTz();
            $table->timestamps();
            $table->index(['community_post_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_comments');
        Schema::dropIfExists('community_attachments');
        Schema::dropIfExists('community_post_versions');
        Schema::dropIfExists('community_posts');
    }
};
