<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account_constraints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('blocker_code', 48)->index();
            $table->string('source_type', 80);
            $table->string('source_id', 100);
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'source_type', 'source_id', 'blocker_code'], 'user_constraint_source_unique');
        });

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('request_type', 24)->index();
            $table->json('scopes');
            $table->string('description', 1000)->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->json('blockers')->nullable();
            $table->foreignId('result_file_id')->nullable()->constrained('uploaded_files')->nullOnDelete();
            $table->timestampTz('result_expires_at')->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->string('resolution_note', 1000)->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key_hash']);
            $table->index(['user_id', 'request_type', 'status']);
        });

        Schema::create('account_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('cooling_off')->index();
            $table->json('eligibility_snapshot');
            $table->boolean('forfeit_points_and_coupons');
            $table->char('idempotency_key_hash', 64);
            $table->timestampTz('cooling_off_ends_at')->index();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key_hash']);
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->string('object_type', 80);
            $table->string('object_id', 100);
            $table->string('reason', 500);
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->index(['object_type', 'object_id', 'released_at']);
        });

        Schema::create('data_deletion_jobs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('privacy_request_id')->nullable()->constrained('privacy_requests')->nullOnDelete();
            $table->foreignId('cancellation_request_id')->nullable()->constrained('account_cancellation_requests')->nullOnDelete();
            $table->string('job_type', 32);
            $table->string('status', 24)->default('pending')->index();
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('stats')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestampTz('run_after')->index();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_deletion_manifests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->char('user_public_id_hash', 64)->index();
            $table->unsignedInteger('deletion_version')->default(1);
            $table->json('scopes');
            $table->timestampTz('effective_at');
            $table->timestampTz('last_replayed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_manifests');
        Schema::dropIfExists('data_deletion_jobs');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('account_cancellation_requests');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('user_account_constraints');
    }
};
