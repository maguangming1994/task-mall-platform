<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestampTz('fulfill_by')->nullable()->index();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('auto_complete_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable();
        });
        Schema::create('fulfillments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('fulfillment_no', 40)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('method', 24);
            $table->string('status', 24)->index();
            $table->string('carrier_code', 40)->nullable();
            $table->string('carrier_name', 80)->nullable();
            $table->string('tracking_no', 80)->nullable();
            $table->string('no_logistics_reason', 500)->nullable();
            $table->json('virtual_delivery_snapshot')->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('fulfilled_at');
            $table->timestamps();
            $table->unique(['carrier_code', 'tracking_no']);
        });
        Schema::create('fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['fulfillment_id', 'order_item_id']);
        });
        Schema::create('fulfillment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fulfillment_id')->constrained()->restrictOnDelete();
            $table->string('provider_event_id', 128)->nullable();
            $table->string('status', 32);
            $table->string('description', 500);
            $table->string('location', 160)->nullable();
            $table->timestampTz('occurred_at');
            $table->char('event_hash', 64);
            $table->timestamps();
            $table->unique(['fulfillment_id', 'event_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_tracking_events');
        Schema::dropIfExists('fulfillment_items');
        Schema::dropIfExists('fulfillments');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['fulfill_by', 'shipped_at', 'auto_complete_at', 'completed_at']));
    }
};
