<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->string('reason_code', 64);
            $table->string('description', 500)->nullable();
            $table->json('evidence_file_ids')->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['reporter_user_id', 'content_type', 'content_id']);
            $table->index(['content_type', 'content_id', 'status']);
        });
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('content_type', 16);
            $table->unsignedBigInteger('content_id');
            $table->foreignId('content_author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action_type', 32);
            $table->string('reason_code', 64);
            $table->string('note', 500);
            $table->string('previous_status', 24);
            $table->boolean('reward_revoked')->default(false);
            $table->string('status', 24)->default('effective')->index();
            $table->foreignId('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('appeal_deadline_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestamps();
            $table->index(['content_type', 'content_id', 'created_at']);
        });
        Schema::create('user_restrictions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('moderation_action_id')->constrained()->restrictOnDelete();
            $table->string('restriction_type', 24);
            $table->string('reason_code', 64);
            $table->string('status', 24)->default('active')->index();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable()->index();
            $table->foreignId('revoked_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'restriction_type', 'status']);
        });
        Schema::create('content_appeals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('moderation_action_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->json('evidence_file_ids')->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_appeals');
        Schema::dropIfExists('user_restrictions');
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('content_reports');
    }
};
