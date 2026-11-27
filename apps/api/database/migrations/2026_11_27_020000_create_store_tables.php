<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('logo_url', 1500)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('contact_phone', 30);
            $table->string('address', 255)->nullable();
            $table->string('business_hours', 60)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('reject_reason', 500)->nullable();
            $table->unsignedSmallInteger('commission_rate_bps')->default(500);
            $table->unsignedBigInteger('shipping_fee_amount')->default(0);
            $table->unsignedBigInteger('free_shipping_threshold')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['campus_id', 'status']);
        });

        Schema::create('store_members', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24)->default('staff');
            $table->string('status', 24)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'user_id']);
        });

        Schema::create('store_manager_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('last_active_at')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('store_categories', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('store_products', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('store_category_id')->nullable()->constrained('store_categories')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('cover_url', 1500)->nullable();
            $table->json('gallery_images')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('admin_off_shelf_reason', 500)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('off_shelf_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('store_product_skus', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('sku_code', 80)->nullable();
            $table->string('name', 160);
            $table->json('specs')->nullable();
            $table->string('image_url', 1500)->nullable();
            $table->string('fulfillment_type', 32)->default('logistics');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedBigInteger('cash_amount')->default(0);
            $table->unsignedInteger('limit_per_order')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index('store_product_id');
        });

        Schema::create('store_inventories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_product_sku_id')->unique()->constrained('store_product_skus')->cascadeOnDelete();
            $table->unsignedBigInteger('on_hand_quantity')->default(0);
            $table->unsignedBigInteger('reserved_quantity')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('store_inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('store_inventory_id')->constrained('store_inventories')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 24)->default('reserved')->index();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_inventory_transactions', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_inventory_id')->constrained('store_inventories')->restrictOnDelete();
            $table->string('transaction_type', 32)->index();
            $table->bigInteger('on_hand_change')->default(0);
            $table->bigInteger('reserved_change')->default(0);
            $table->unsignedBigInteger('on_hand_before');
            $table->unsignedBigInteger('on_hand_after');
            $table->unsignedBigInteger('reserved_before');
            $table->unsignedBigInteger('reserved_after');
            $table->string('business_type', 48)->nullable();
            $table->string('business_id', 80)->nullable();
            $table->string('reason', 500);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('store_product_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_product_id')->constrained('store_products')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('action', 24);
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('store_settlements', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('batch_no', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('net_amount');
            $table->unsignedInteger('item_count');
            $table->string('status', 24)->default('pending')->index();
            $table->string('remark', 500)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('store_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_settlement_id')->constrained('store_settlements')->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('net_amount');
            $table->timestamps();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('order_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('store_sku_id')->nullable()->after('store_id')->constrained('store_product_skus')->restrictOnDelete();
            $table->string('store_name', 160)->nullable()->after('store_sku_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_sku_id')->nullable()->change();
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('store_sku_id')->nullable()->after('product_sku_id')->constrained('store_product_skus')->cascadeOnDelete();
            $table->unique(['cart_id', 'store_sku_id', 'purchase_mode']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_sku_id')->nullable()->change();
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('created_by_admin_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('media_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'store_sku_id', 'purchase_mode']);
            $table->dropConstrainedForeignId('store_sku_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('store_sku_id');
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn('store_name');
        });

        Schema::dropIfExists('store_settlement_items');
        Schema::dropIfExists('store_settlements');
        Schema::dropIfExists('store_product_status_changes');
        Schema::dropIfExists('store_inventory_transactions');
        Schema::dropIfExists('store_inventory_reservations');
        Schema::dropIfExists('store_inventories');
        Schema::dropIfExists('store_product_skus');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('store_categories');
        Schema::dropIfExists('store_manager_sessions');
        Schema::dropIfExists('store_members');
        Schema::dropIfExists('stores');
    }
};
