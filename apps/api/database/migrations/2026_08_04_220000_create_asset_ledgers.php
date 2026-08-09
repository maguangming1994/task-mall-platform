<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_code', 48)->index();
            $table->string('source_business_id', 100)->nullable();
            $table->unsignedBigInteger('issued_points');
            $table->unsignedBigInteger('remaining_points');
            $table->unsignedBigInteger('frozen_points')->default(0);
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('status', 24)->default('available')->index();
            $table->char('event_key_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('point_debts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('source_code', 48);
            $table->string('source_business_id', 100)->nullable();
            $table->unsignedBigInteger('original_debt_points');
            $table->unsignedBigInteger('remaining_debt_points');
            $table->string('status', 24)->default('outstanding')->index();
            $table->string('reason', 500);
            $table->char('event_key_hash', 64)->unique();
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('point_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('point_debt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('transaction_type', 24);
            $table->bigInteger('change_available');
            $table->bigInteger('change_frozen')->default(0);
            $table->bigInteger('change_debt')->default(0);
            $table->unsignedBigInteger('available_after');
            $table->unsignedBigInteger('frozen_after');
            $table->unsignedBigInteger('debt_after');
            $table->string('source_code', 48)->nullable();
            $table->string('source_business_id', 100)->nullable();
            $table->char('event_key_hash', 64)->unique();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamps();
        });

        Schema::create('point_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('point_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('point_batch_id')->constrained()->restrictOnDelete();
            $table->string('allocation_type', 24);
            $table->unsignedBigInteger('points');
            $table->timestamps();
            $table->unique(['point_transaction_id', 'point_batch_id', 'allocation_type']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('transaction_type', 24);
            $table->bigInteger('change_available_cents');
            $table->bigInteger('change_frozen_cents')->default(0);
            $table->unsignedBigInteger('available_after_cents');
            $table->unsignedBigInteger('frozen_after_cents');
            $table->string('business_type', 48)->nullable();
            $table->string('business_id', 100)->nullable();
            $table->char('event_key_hash', 64)->unique();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamps();
        });

        Schema::create('coupon_templates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->unsignedBigInteger('cash_discount_cents')->default(0);
            $table->unsignedBigInteger('points_discount')->default(0);
            $table->unsignedBigInteger('minimum_cash_cents')->default(0);
            $table->unsignedBigInteger('minimum_points')->default(0);
            $table->unsignedInteger('valid_days')->default(30);
            $table->unsignedInteger('total_quantity');
            $table->unsignedInteger('issued_quantity')->default(0);
            $table->string('status', 24)->default('active')->index();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('user_coupons', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('coupon_template_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('unused')->index();
            $table->json('template_snapshot');
            $table->char('issue_event_key_hash', 64)->unique();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'valid_to']);
        });

        Schema::create('coupon_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_coupon_id')->constrained()->restrictOnDelete();
            $table->string('transaction_type', 24);
            $table->string('status_before', 24)->nullable();
            $table->string('status_after', 24);
            $table->string('business_type', 48)->nullable();
            $table->string('business_id', 100)->nullable();
            $table->char('event_key_hash', 64)->unique();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamps();
        });

        Schema::create('asset_adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('asset_type', 24);
            $table->string('action', 24);
            $table->unsignedBigInteger('amount')->nullable();
            $table->foreignId('coupon_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->boolean('requires_review');
            $table->string('reason', 500);
            $table->foreignId('requested_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->char('review_idempotency_key_hash', 64)->nullable()->unique();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_adjustment_requests');
        Schema::dropIfExists('coupon_transactions');
        Schema::dropIfExists('user_coupons');
        Schema::dropIfExists('coupon_templates');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('point_batch_allocations');
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('point_debts');
        Schema::dropIfExists('point_batches');
    }
};
