<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('account', 100)->unique();
            $table->string('display_name', 80);
            $table->string('email', 191)->nullable()->unique();
            $table->string('password');
            $table->string('status', 24)->default('active')->index();
            $table->boolean('requires_mfa')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->boolean('system_role')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 100);
            $table->string('group', 64)->index();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('admin_user_role', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['admin_user_id', 'role_id']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('admin_user_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('admin_users');
    }
};
