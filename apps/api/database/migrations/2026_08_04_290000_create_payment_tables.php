<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('payment_no', 40)->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 24);
            $table->string('scene', 24);
            $table->unsignedBigInteger('cash_amount');
            $table->string('status', 24)->index();
            $table->string('provider_trade_no', 128)->nullable()->unique();
            $table->json('client_payload')->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('status', 24);
            $table->string('provider_request_id', 128)->nullable();
            $table->json('request_summary');
            $table->json('response_summary')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
