<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24);
            $table->string('notification_id', 128);
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('trade_state', 32)->nullable();
            $table->char('resource_hash', 64);
            $table->json('headers_summary');
            $table->string('status', 24)->index();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'notification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
    }
};
