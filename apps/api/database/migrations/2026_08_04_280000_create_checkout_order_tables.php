<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_previews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('request_hash', 64);
            $table->json('request_snapshot');
            $table->json('pricing_snapshot');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('batch_no', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('client_platform', 24);
            $table->unsignedInteger('capability_version');
            $table->string('status', 24)->default('active')->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('checkout_preview_id')->constrained()->restrictOnDelete()->unique();
            $table->unsignedBigInteger('items_cash_amount');
            $table->unsignedBigInteger('items_points');
            $table->unsignedBigInteger('cash_discount_amount');
            $table->unsignedBigInteger('points_discount');
            $table->unsignedBigInteger('shipping_amount');
            $table->unsignedBigInteger('payable_cash_amount');
            $table->unsignedBigInteger('payable_points');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('order_no', 40)->unique();
            $table->foreignId('order_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('fulfillment_type', 32)->index();
            $table->string('status', 32)->index();
            $table->string('cash_payment_method', 24);
            $table->json('address_snapshot')->nullable();
            $table->foreignId('user_coupon_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('coupon_snapshot')->nullable();
            $table->json('shipping_rule_snapshot')->nullable();
            $table->unsignedBigInteger('items_cash_amount');
            $table->unsignedBigInteger('items_points');
            $table->unsignedBigInteger('cash_discount_amount');
            $table->unsignedBigInteger('points_discount');
            $table->unsignedBigInteger('shipping_amount');
            $table->unsignedBigInteger('payable_cash_amount');
            $table->unsignedBigInteger('payable_points');
            $table->unsignedBigInteger('paid_wallet_amount')->default(0);
            $table->unsignedBigInteger('paid_external_amount')->default(0);
            $table->unsignedBigInteger('paid_points')->default(0);
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_sku_id')->constrained()->restrictOnDelete();
            $table->string('product_public_id', 26);
            $table->string('sku_public_id', 26);
            $table->string('product_name', 160);
            $table->string('sku_name', 160);
            $table->json('specs_snapshot');
            $table->string('image_file_public_id', 26)->nullable();
            $table->string('purchase_mode', 24);
            $table->string('fulfillment_type', 32);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cash_amount');
            $table->unsignedBigInteger('unit_points');
            $table->unsignedBigInteger('cash_subtotal');
            $table->unsignedBigInteger('points_subtotal');
            $table->unsignedBigInteger('cash_discount_allocated')->default(0);
            $table->unsignedBigInteger('points_discount_allocated')->default(0);
            $table->unsignedBigInteger('shipping_allocated')->default(0);
            $table->unsignedBigInteger('paid_cash_amount')->default(0);
            $table->unsignedBigInteger('paid_points')->default(0);
            $table->unsignedInteger('refunded_quantity')->default(0);
            $table->json('purchase_limit_snapshot');
            $table->timestamps();
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete()->unique();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 24)->default('reserved')->index();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_point_reservations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete()->unique();
            $table->unsignedBigInteger('points');
            $table->string('status', 24)->default('frozen')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_point_reservation_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_point_reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('point_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('points');
            $table->timestamps();
        });

        Schema::create('order_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('event', 48);
            $table->string('reason', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_changes');
        Schema::dropIfExists('order_point_reservation_allocations');
        Schema::dropIfExists('order_point_reservations');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_batches');
        Schema::dropIfExists('checkout_previews');
    }
};
