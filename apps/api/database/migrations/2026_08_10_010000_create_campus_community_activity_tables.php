<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->unsignedInteger('like_count')->default(0)->after('comment_count');
        });

        Schema::create('community_post_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['community_post_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('community_activities', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('creator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category_code', 24)->index();
            $table->string('title', 80);
            $table->text('details');
            $table->string('location', 160);
            $table->timestampTz('starts_at')->index();
            $table->unsignedInteger('participant_limit');
            $table->unsignedInteger('participant_count')->default(0);
            $table->string('status', 24)->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_reason_code', 64)->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at', 'id']);
            $table->index(['creator_user_id', 'created_at']);
        });

        Schema::create('community_activity_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->unique(['community_activity_id', 'uploaded_file_id']);
        });

        Schema::create('community_activity_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->index();
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->timestamps();
            $table->unique(['community_activity_id', 'user_id']);
            $table->index(['user_id', 'status', 'joined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_activity_participants');
        Schema::dropIfExists('community_activity_images');
        Schema::dropIfExists('community_activities');
        Schema::dropIfExists('community_post_likes');

        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropColumn('like_count');
        });
    }
};
