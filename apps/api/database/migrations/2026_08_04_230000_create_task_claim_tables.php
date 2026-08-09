<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_claims', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('enrollment_source', 16);
            $table->string('cycle_key', 64);
            $table->json('task_snapshot');
            $table->string('status', 24)->default('claimed')->index();
            $table->string('slot_status', 16)->default('reserved')->index();
            $table->timestampTz('progress_started_at')->nullable();
            $table->timestampTz('completion_deadline_at')->index();
            $table->unsignedSmallInteger('cancel_count')->default(0);
            $table->unsignedInteger('submission_count')->default(0);
            $table->unsignedInteger('appeal_count')->default(0);
            $table->string('reward_status', 24)->default('pending');
            $table->char('claim_idempotency_key_hash', 64)->unique();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id', 'cycle_key']);
            $table->index(['user_id', 'status', 'updated_at']);
        });

        Schema::create('task_reward_reservations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_reward_budget_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reward_sequence');
            $table->string('asset_type', 24);
            $table->unsignedBigInteger('amount');
            $table->string('status', 24)->default('reserved')->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('reserved_at');
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['task_claim_id', 'reward_sequence']);
        });

        Schema::create('task_claim_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_claim_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('action', 32);
            $table->string('reason', 500);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_claim_status_changes');
        Schema::dropIfExists('task_reward_reservations');
        Schema::dropIfExists('task_claims');
    }
};
