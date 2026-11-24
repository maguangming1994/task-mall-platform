<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_xp_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48)->unique();
            $table->text('api_key')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('robot_message_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('idempotency_key_hash', 64);
            $table->string('device_id', 191);
            $table->string('resolved_device_id', 191)->nullable();
            $table->string('wxid', 191);
            $table->string('group_id', 191);
            $table->text('message');
            $table->string('cmd_id', 191)->nullable()->index();
            $table->string('status', 32)->default('SUBMITTING')->index();
            $table->string('provider_status', 64)->nullable();
            $table->boolean('queued')->default(false);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by_admin_id', 'idempotency_key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_message_requests');
        Schema::dropIfExists('robot_xp_credentials');
    }
};
