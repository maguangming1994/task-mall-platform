<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_templates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 120);
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('current_version_no')->default(1);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->char('idempotency_key_hash', 64);
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by_admin_id', 'idempotency_key_hash']);
        });

        Schema::create('shipping_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('content');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['shipping_template_id', 'version_no']);
        });

        Schema::table('product_skus', function (Blueprint $table) {
            $table->foreignId('shipping_template_id')->nullable()->after('fulfillment_type')->constrained()->restrictOnDelete();
            $table->unsignedInteger('weight_grams')->nullable()->after('shipping_template_id');
            $table->boolean('free_shipping')->default(false)->after('weight_grams');
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('free_shipping');
            $table->unsignedInteger('limit_per_order')->nullable();
            $table->unsignedInteger('limit_per_day')->nullable();
            $table->unsignedInteger('limit_campaign')->nullable();
            $table->unsignedInteger('limit_lifetime')->nullable();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_sku_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedBigInteger('on_hand_quantity')->default(0);
            $table->unsignedBigInteger('reserved_quantity')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->string('transaction_type', 32)->index();
            $table->bigInteger('on_hand_change');
            $table->bigInteger('reserved_change')->default(0);
            $table->unsignedBigInteger('on_hand_before');
            $table->unsignedBigInteger('on_hand_after');
            $table->unsignedBigInteger('reserved_before');
            $table->unsignedBigInteger('reserved_after');
            $table->string('business_type', 48)->nullable();
            $table->string('business_id', 80)->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_sku_id')->constrained()->restrictOnDelete();
            $table->string('purchase_mode', 24);
            $table->unsignedInteger('quantity');
            $table->boolean('selected')->default(true);
            $table->timestamps();
            $table->unique(['cart_id', 'product_sku_id', 'purchase_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('inventories');
        Schema::table('product_skus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_template_id');
            $table->dropColumn(['weight_grams', 'free_shipping', 'low_stock_threshold', 'limit_per_order', 'limit_per_day', 'limit_campaign', 'limit_lifetime']);
        });
        Schema::dropIfExists('shipping_template_versions');
        Schema::dropIfExists('shipping_templates');
    }
};
