<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_appeals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_submission_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('appeal_no');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('submitted')->index();
            $table->string('reason', 1000);
            $table->json('evidence_file_public_ids')->nullable();
            $table->foreignId('original_reviewer_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('reviewer_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('decision', 16)->nullable();
            $table->string('result_reason', 1000)->nullable();
            $table->char('create_idempotency_key_hash', 64)->unique();
            $table->char('review_idempotency_key_hash', 64)->nullable()->unique();
            $table->timestampTz('appeal_deadline_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['task_claim_id', 'appeal_no']);
        });

        Schema::create('task_submission_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_submission_id')->unique()->constrained()->restrictOnDelete();
            $table->char('normalized_text_hash', 64)->nullable()->index();
            $table->json('file_hashes');
            $table->string('feature_version', 24)->default('v1');
            $table->timestamps();
        });

        Schema::create('task_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('object_type', 32);
            $table->unsignedBigInteger('object_id');
            $table->string('stage', 24);
            $table->string('rule_version', 24);
            $table->json('hit_codes');
            $table->string('risk_level', 16)->index();
            $table->json('evidence_summary');
            $table->string('recommendation', 64);
            $table->boolean('requires_manual_review')->default(false);
            $table->timestamps();
            $table->index(['object_type', 'object_id', 'stage']);
        });

        Schema::create('task_risk_cases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_claim_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 32);
            $table->string('risk_level', 16)->index();
            $table->string('status', 24)->default('open')->index();
            $table->boolean('reward_frozen')->default(false);
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('conclusion', 1000)->nullable();
            $table->timestampTz('response_due_at')->index();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_reports', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('task_risk_case_id')->constrained()->restrictOnDelete();
            $table->string('reason_code', 32);
            $table->string('description', 1000);
            $table->json('evidence_file_public_ids')->nullable();
            $table->string('status', 24)->default('open')->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
            $table->index(['task_id', 'reporter_user_id', 'status']);
        });

        Schema::create('task_risk_actions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_risk_case_id')->constrained()->restrictOnDelete();
            $table->string('action', 48);
            $table->string('reason', 1000);
            $table->foreignId('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->timestamps();
            $table->unique(['task_risk_case_id', 'action', 'idempotency_key_hash']);
        });

        Schema::create('task_publishers', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('publisher_type', 24)->default('platform_admin');
            $table->foreignId('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('verification_status', 24)->default('verified');
            $table->string('publisher_level', 24)->default('platform');
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
            $table->unique(['publisher_type', 'admin_user_id']);
        });

        Schema::create('task_publisher_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_publisher_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('last_active_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('referral_code', 32)->index();
            $table->timestampTz('bound_at');
            $table->foreignId('first_task_claim_id')->nullable()->constrained('task_claims')->restrictOnDelete();
            $table->timestampTz('first_task_completed_at')->nullable();
            $table->string('qualifying_order_public_id', 26)->nullable();
            $table->timestampTz('first_cash_order_completed_at')->nullable();
            $table->string('status', 24)->default('tracking')->index();
            $table->timestampTz('qualified_at')->nullable();
            $table->string('reward_event_key', 100)->nullable()->unique();
            $table->timestampTz('rewarded_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('task_publisher_sessions');
        Schema::dropIfExists('task_publishers');
        Schema::dropIfExists('task_risk_actions');
        Schema::dropIfExists('task_reports');
        Schema::dropIfExists('task_risk_cases');
        Schema::dropIfExists('task_risk_assessments');
        Schema::dropIfExists('task_submission_fingerprints');
        Schema::dropIfExists('task_appeals');
    }
};
