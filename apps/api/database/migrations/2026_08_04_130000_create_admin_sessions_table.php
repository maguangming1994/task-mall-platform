<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('admin_user_id')->constrained()->restrictOnDelete();
            $table->char('refresh_token_hash', 64)->unique();
            $table->timestampTz('issued_at');
            $table->timestampTz('last_active_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
    }
};
