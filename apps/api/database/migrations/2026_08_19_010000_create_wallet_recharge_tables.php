<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_recharges', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('recharge_no', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('method', 24);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 24)->index();
            $table->string('payer_name', 80)->nullable();
            $table->string('offline_reference', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('offline_proof_file_ids')->nullable();
            $table->foreignId('reviewed_by_admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->char('idempotency_key_hash', 64)->unique();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('wallet_recharge_cards', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->char('code_hash', 64)->unique();
            $table->string('code_mask', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 24)->default('active')->index();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_recharge_id')->nullable()->constrained('wallet_recharges')->nullOnDelete();
            $table->timestampTz('redeemed_at')->nullable();
            $table->foreignId('created_by_admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('wallet_recharge_id')->nullable()->after('order_id')->constrained('wallet_recharges')->restrictOnDelete();
            $table->index(['wallet_recharge_id', 'status']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['wallet_recharge_id']);
            $table->dropIndex(['wallet_recharge_id', 'status']);
            $table->dropColumn('wallet_recharge_id');
        });
        Schema::dropIfExists('wallet_recharge_cards');
        Schema::dropIfExists('wallet_recharges');
    }
};
