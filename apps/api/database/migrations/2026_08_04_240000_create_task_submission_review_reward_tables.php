<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('submission_no');
            $table->string('status', 24)->default('submitted')->index();
            $table->json('content_snapshot');
            $table->json('file_public_ids')->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('submitted_at');
            $table->timestampTz('review_started_at')->nullable();
            $table->timestampTz('review_due_at')->index();
            $table->timestampTz('review_reminded_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['task_claim_id', 'submission_no']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('task_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason_code', 64)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('review_snapshot')->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('reviewed_at');
            $table->timestamps();
        });

        Schema::create('task_verification_events', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_claim_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('source', 64);
            $table->char('event_key_hash', 64)->unique();
            $table->json('payload');
            $table->boolean('goal_met')->default(false);
            $table->string('status', 24)->default('received')->index();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_reward_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_reward_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('asset_type', 24);
            $table->unsignedBigInteger('amount');
            $table->json('reward_snapshot');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable()->index();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['task_claim_id', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('task_reward_deliveries');
        Schema::dropIfExists('task_verification_events');
        Schema::dropIfExists('task_reviews');
        Schema::dropIfExists('task_submissions');
    }
};
