<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('union_id', 191)->nullable()->index();
            $table->json('metadata')->default('{}');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('device_fingerprint_hash', 191);
            $table->string('client_platform', 32);
            $table->string('device_name', 120)->nullable();
            $table->timestampTz('last_active_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_fingerprint_hash', 'client_platform']);
            $table->index(['user_id', 'revoked_at', 'last_active_at']);
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_device_id')->constrained('user_devices')->restrictOnDelete();
            $table->char('refresh_token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('user_identities');
    }
};
