<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_budget_pools', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 100);
            $table->string('asset_type', 24)->index();
            $table->char('coupon_template_public_id', 26)->nullable();
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('locked_amount')->default(0);
            $table->unsignedBigInteger('issued_amount')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->timestamps();
            $table->unique(['created_by_admin_id', 'idempotency_key_hash']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->string('creation_channel', 24)->default('admin_web');
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('current_version_no')->default(1);
            $table->unsignedInteger('published_version_no')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->char('idempotency_key_hash', 64);
            $table->timestampTz('scheduled_at')->nullable()->index();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by_admin_id', 'idempotency_key_hash']);
        });

        Schema::create('task_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status', 24)->default('draft')->index();
            $table->string('change_type', 24)->default('initial');
            $table->unsignedInteger('source_version_no')->nullable();
            $table->json('changed_fields');
            $table->json('content');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'version_no']);
        });

        Schema::create('task_publish_approvals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_version_id')->constrained()->restrictOnDelete()->unique();
            $table->foreignId('requested_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->json('triggered_rules');
            $table->json('threshold_snapshot');
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->char('review_idempotency_key_hash', 64)->nullable()->unique();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_reward_budgets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('reward_budget_pool_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('reward_sequence');
            $table->string('asset_type', 24);
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedInteger('max_deliveries_total');
            $table->unsignedInteger('max_deliveries_per_cycle')->nullable();
            $table->unsignedBigInteger('locked_amount');
            $table->unsignedBigInteger('reserved_amount')->default(0);
            $table->unsignedBigInteger('issued_amount')->default(0);
            $table->unsignedBigInteger('released_amount')->default(0);
            $table->unsignedBigInteger('recovered_amount')->default(0);
            $table->string('status', 24)->default('locked');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['task_version_id', 'reward_sequence']);
        });

        Schema::create('reward_budget_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('reward_budget_pool_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('task_reward_budget_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('transaction_type', 32);
            $table->bigInteger('change_amount');
            $table->unsignedBigInteger('total_after');
            $table->unsignedBigInteger('locked_after');
            $table->unsignedBigInteger('issued_after');
            $table->char('idempotency_key', 64)->unique();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('task_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->string('action', 32);
            $table->string('reason', 500);
            $table->string('existing_claim_policy', 48)->nullable();
            $table->foreignId('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_status_changes');
        Schema::dropIfExists('reward_budget_transactions');
        Schema::dropIfExists('task_reward_budgets');
        Schema::dropIfExists('task_publish_approvals');
        Schema::dropIfExists('task_versions');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('reward_budget_pools');
    }
};
