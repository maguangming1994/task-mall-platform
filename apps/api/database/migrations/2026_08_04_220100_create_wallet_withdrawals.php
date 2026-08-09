<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('withdrawal_no', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('requested_amount_cents');
            $table->unsignedInteger('fee_rate_ppm');
            $table->unsignedBigInteger('fee_amount_cents');
            $table->unsignedBigInteger('received_amount_cents');
            $table->string('channel', 24);
            $table->string('status', 32)->default('reviewing')->index();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->char('review_idempotency_key_hash', 64)->nullable()->unique();
            $table->string('channel_reference', 100)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_withdrawals');
    }
};
