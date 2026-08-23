<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 160);
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('provider_state', 32)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->char('resource_hash', 64);
            $table->string('status', 24)->index();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('wallet_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('wallet_withdrawal_id')->unique()->constrained()->restrictOnDelete();
            $table->string('payout_no', 40)->unique();
            $table->string('provider', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 24)->index();
            $table->string('provider_reference', 128)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('last_queried_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_payout_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 160);
            $table->foreignId('wallet_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('provider_state', 32)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->char('resource_hash', 64);
            $table->string('status', 24)->index();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_payout_events');
        Schema::dropIfExists('wallet_payouts');
        Schema::dropIfExists('refund_provider_events');
    }
};
