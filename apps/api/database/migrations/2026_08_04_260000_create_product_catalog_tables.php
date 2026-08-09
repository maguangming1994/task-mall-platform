<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('subtitle', 255)->nullable();
            $table->text('description');
            $table->char('cover_file_public_id', 26)->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('current_version_no')->default(1);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->char('idempotency_key_hash', 64);
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('off_shelf_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by_admin_id', 'idempotency_key_hash']);
        });

        Schema::create('product_skus', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku_code', 80)->unique();
            $table->string('name', 160);
            $table->json('specs');
            $table->char('image_file_public_id', 26)->nullable();
            $table->string('fulfillment_type', 32)->index();
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('sku_purchase_options', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('product_sku_id')->constrained()->cascadeOnDelete();
            $table->string('purchase_mode', 24)->index();
            $table->unsignedBigInteger('cash_amount')->default(0);
            $table->unsignedBigInteger('points')->default(0);
            $table->boolean('enabled')->default(true)->index();
            $table->json('channels');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['product_sku_id', 'purchase_mode']);
        });

        Schema::create('product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->json('snapshot');
            $table->json('changed_fields');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'version_no']);
        });

        Schema::create('product_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->string('action', 24);
            $table->string('reason', 500);
            $table->foreignId('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_status_changes');
        Schema::dropIfExists('product_versions');
        Schema::dropIfExists('sku_purchase_options');
        Schema::dropIfExists('product_skus');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
