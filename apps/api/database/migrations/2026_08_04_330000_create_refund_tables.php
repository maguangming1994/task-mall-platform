<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedBigInteger('refunded_wallet_amount')->default(0);
            $t->unsignedBigInteger('refunded_external_amount')->default(0);
            $t->unsignedBigInteger('refunded_points')->default(0);
        });
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedBigInteger('refunded_cash_amount')->default(0);
            $t->unsignedBigInteger('refunded_points')->default(0);
        });
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('refund_no', 40)->unique();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('service_type', 24);
            $t->string('reason_code', 64);
            $t->string('description', 500)->nullable();
            $t->string('status', 24)->index();
            $t->unsignedBigInteger('refund_cash_amount');
            $t->unsignedBigInteger('refund_wallet_amount');
            $t->unsignedBigInteger('refund_external_amount');
            $t->unsignedBigInteger('refund_points');
            $t->boolean('coupon_returned')->default(false);
            $t->char('idempotency_key_hash', 64)->unique();
            $t->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $t->string('review_note', 500)->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('refund_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('refund_cash_amount');
            $t->unsignedBigInteger('refund_points');
            $t->unsignedInteger('remaining_refundable_snapshot');
            $t->timestamps();
            $t->unique(['refund_id', 'order_item_id']);
        });
        Schema::create('refund_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_id')->constrained()->restrictOnDelete();
            $t->string('component_type', 24);
            $t->unsignedBigInteger('amount');
            $t->string('status', 24);
            $t->string('provider_refund_no', 128)->nullable();
            $t->unsignedInteger('retry_count')->default(0);
            $t->string('error_message', 500)->nullable();
            $t->timestamps();
            $t->unique(['refund_id', 'component_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_components');
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['refunded_cash_amount', 'refunded_points']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['refunded_wallet_amount', 'refunded_external_amount', 'refunded_points']));
    }
};
