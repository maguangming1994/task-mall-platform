<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_storage_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->string('display_name', 80);
            $table->boolean('enabled')->default(false)->index();
            $table->boolean('active')->default(false)->index();
            $table->string('endpoint', 500)->nullable();
            $table->string('region', 80)->nullable();
            $table->string('bucket', 120)->nullable();
            $table->string('public_base_url', 500)->nullable();
            $table->text('access_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('source_type', 24);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->string('storage_provider', 32)->index();
            $table->string('object_path', 1000);
            $table->string('public_url', 1500);
            $table->string('status', 24)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['storage_provider', 'object_path']);
        });

        Schema::create('media_storage_migrations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('from_provider', 32);
            $table->string('to_provider', 32);
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('total_assets')->default(0);
            $table->unsignedBigInteger('processed_assets')->default(0);
            $table->unsignedBigInteger('failed_assets')->default(0);
            $table->boolean('delete_source')->default(false);
            $table->json('error_summary')->nullable();
            $table->foreignId('requested_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        DB::table('media_storage_providers')->insert([
            ['provider' => 'local', 'display_name' => '本地公共存储', 'enabled' => true, 'active' => true, 'public_base_url' => rtrim((string) config('app.url'), '/').'/storage', 'created_at' => now(), 'updated_at' => now()],
            ['provider' => 'tencent_cos', 'display_name' => '腾讯云 COS', 'enabled' => false, 'active' => false, 'public_base_url' => null, 'created_at' => now(), 'updated_at' => now()],
            ['provider' => 'qiniu_kodo', 'display_name' => '七牛云 Kodo', 'enabled' => false, 'active' => false, 'public_base_url' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('media_storage_migrations');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('media_storage_providers');
    }
};
