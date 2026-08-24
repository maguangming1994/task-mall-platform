<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48)->unique();
            $table->text('access_key_id')->nullable();
            $table->text('access_key_secret')->nullable();
            $table->text('security_token')->nullable();
            $table->timestamps();
        });

        Schema::create('user_identity_verifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 48);
            $table->string('status', 24);
            $table->string('real_name_masked', 80);
            $table->string('identity_number_masked', 32);
            $table->string('identity_number_hash', 64);
            $table->string('provider_request_id', 128)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['identity_number_hash', 'status']);
        });

        DB::table('system_setting_definitions')
            ->where('setting_key', 'identity.verification')
            ->update([
                'default_value' => json_encode([
                    'driver' => 'disabled',
                    'collection_enabled' => false,
                    'manual_review_enabled' => false,
                    'aliyun' => [
                        'region' => 'cn-hangzhou',
                        'endpoint' => 'cloudauth.aliyuncs.com',
                        'timeout_seconds' => 8,
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'validation_rules' => json_encode([
                    'required_keys' => ['driver', 'collection_enabled', 'manual_review_enabled', 'aliyun'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identity_verifications');
        Schema::dropIfExists('identity_provider_credentials');
    }
};
