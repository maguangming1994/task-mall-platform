<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('express_pickup_runners', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->string('real_name', 50);
            $table->string('phone', 20);
            $table->timestampTz('applied_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('reject_reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['campus_id', 'user_id']);
        });

        Schema::create('express_pickup_orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('order_no', 40)->unique();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('runner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending_payment');
            $table->string('pickup_site', 100);
            $table->string('pickup_code', 64);
            $table->string('package_info', 200)->nullable();
            $table->unsignedSmallInteger('package_count')->default(1);
            $table->string('deliver_building', 100);
            $table->string('deliver_address', 200);
            $table->string('contact_name', 50);
            $table->string('contact_phone', 20);
            $table->unsignedInteger('reward_cents');
            $table->unsignedInteger('total_cents');
            $table->string('payment_method', 16)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedInteger('paid_amount_cents')->nullable();
            $table->timestampTz('expected_at')->nullable();
            $table->string('remark', 300)->nullable();
            $table->char('idempotency_key_hash', 64)->nullable()->unique();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable()->index();
            $table->timestampTz('printed_at')->nullable();
            $table->string('print_status', 16)->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['runner_user_id', 'status']);
            $table->index(['status', 'settled_at']);
        });

        Schema::create('express_pickup_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('express_pickup_orders')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('event', 48);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 200)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        Schema::create('express_pickup_settlements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('batch_no', 40)->unique();
            $table->foreignId('campus_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('runner_count')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('express_pickup_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('express_pickup_settlements')->cascadeOnDelete();
            $table->foreignId('runner_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['settlement_id', 'runner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('express_pickup_settlement_items');
        Schema::dropIfExists('express_pickup_settlements');
        Schema::dropIfExists('express_pickup_order_events');
        Schema::dropIfExists('express_pickup_orders');
        Schema::dropIfExists('express_pickup_runners');
    }
};
