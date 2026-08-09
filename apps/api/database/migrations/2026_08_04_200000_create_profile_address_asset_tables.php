<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('recipient_name');
            $table->text('phone');
            $table->string('province', 80);
            $table->string('city', 80);
            $table->string('district', 80);
            $table->text('detail');
            $table->string('postal_code', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->timestamps();
            $table->softDeletesTz();
            $table->unique(['user_id', 'idempotency_key_hash']);
            $table->index(['user_id', 'is_default', 'updated_at']);
        });

        Schema::create('user_asset_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('points_available')->default(0);
            $table->unsignedBigInteger('points_frozen')->default(0);
            $table->unsignedBigInteger('points_debt')->default(0);
            $table->unsignedBigInteger('wallet_available_cents')->default(0);
            $table->unsignedBigInteger('wallet_frozen_cents')->default(0);
            $table->unsignedInteger('coupon_available')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('user_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('milestone_code', 48);
            $table->string('reward_status', 24)->default('pending');
            $table->timestampTz('achieved_at');
            $table->timestampTz('rewarded_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'milestone_code']);
            $table->index(['milestone_code', 'reward_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_milestones');
        Schema::dropIfExists('user_asset_accounts');
        Schema::dropIfExists('user_addresses');
    }
};
