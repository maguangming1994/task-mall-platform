<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48)->unique();
            $table->text('access_key_id')->nullable();
            $table->text('access_key_secret')->nullable();
            $table->text('security_token')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        // Keep the non-secret provider options in the existing settings
        // registry. Credentials intentionally have no representation here.
        $now = now();
        DB::table('system_setting_definitions')->updateOrInsert(
            ['setting_key' => 'auth.phone_verification'],
            [
                'group_code' => 'accounts',
                'name' => '手机号验证码与短信网关',
                'description' => '维护验证码有效期、限流、短信驱动及阿里云短信签名模板。访问凭据单独加密保存。',
                'value_type' => 'object',
                'unit' => null,
                'options' => null,
                'validation_rules' => json_encode([
                    'required_keys' => [
                        'driver', 'ttl_seconds', 'max_attempts', 'resend_interval_seconds',
                        'hourly_limit', 'daily_limit', 'aliyun',
                    ],
                    'properties' => [
                        'ttl_seconds' => ['type' => 'integer', 'min' => 60, 'max' => 3600],
                        'max_attempts' => ['type' => 'integer', 'min' => 1, 'max' => 10],
                        'resend_interval_seconds' => ['type' => 'integer', 'min' => 0, 'max' => 3600],
                        'hourly_limit' => ['type' => 'integer', 'min' => 1, 'max' => 100],
                        'daily_limit' => ['type' => 'integer', 'min' => 1, 'max' => 1000],
                        'aliyun' => ['type' => 'object'],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'default_value' => json_encode([
                    'driver' => 'log',
                    'ttl_seconds' => 300,
                    'max_attempts' => 5,
                    'resend_interval_seconds' => 60,
                    'hourly_limit' => 5,
                    'daily_limit' => 10,
                    'aliyun' => [
                        'region' => 'cn-hangzhou',
                        'endpoint' => 'dysmsapi.aliyuncs.com',
                        'sign_name' => '',
                        'template_code' => '',
                        'verification_variables' => ['code', 'minutes'],
                        'timeout_seconds' => 5,
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'risk_level' => 'normal',
                'enabled' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('system_setting_definitions')
            ->where('setting_key', 'auth.phone_verification')
            ->delete();
        Schema::dropIfExists('sms_provider_credentials');
    }
};
