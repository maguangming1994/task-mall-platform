<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_campuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->restrictOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'campus_id']);
            $table->index(['campus_id', 'status']);
        });

        // 存量门店：主校区即首个开通校区
        $now = now();
        foreach (DB::table('stores')->whereNotNull('campus_id')->get(['id', 'campus_id']) as $store) {
            DB::table('store_campuses')->insertOrIgnore([
                'store_id' => $store->id,
                'campus_id' => $store->campus_id,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('store_inventories', function (Blueprint $table): void {
            $table->foreignId('campus_id')->nullable()->after('store_product_sku_id')->constrained('campuses')->restrictOnDelete();
        });
        DB::statement('UPDATE store_inventories SET campus_id = (SELECT store_products.campus_id FROM store_products INNER JOIN store_product_skus ON store_product_skus.store_product_id = store_products.id WHERE store_product_skus.id = store_inventories.store_product_sku_id)');
        Schema::table('store_inventories', function (Blueprint $table): void {
            $table->dropUnique(['store_product_sku_id']);
            $table->unique(['store_product_sku_id', 'campus_id']);
        });

        Schema::table('store_settlements', function (Blueprint $table): void {
            $table->foreignId('campus_id')->nullable()->after('store_id')->constrained('campuses')->restrictOnDelete();
        });
        DB::statement('UPDATE store_settlements SET campus_id = (SELECT stores.campus_id FROM stores WHERE stores.id = store_settlements.store_id)');
        Schema::table('store_settlements', function (Blueprint $table): void {
            $table->index(['store_id', 'campus_id', 'status'], 'store_settlements_store_campus_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('store_settlements', function (Blueprint $table): void {
            $table->dropIndex('store_settlements_store_campus_status_index');
            $table->dropConstrainedForeignId('campus_id');
        });

        Schema::table('store_inventories', function (Blueprint $table): void {
            $table->dropUnique(['store_product_sku_id', 'campus_id']);
            $table->unique(['store_product_sku_id']);
            $table->dropConstrainedForeignId('campus_id');
        });

        Schema::dropIfExists('store_campuses');
    }
};
