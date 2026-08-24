<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wechat_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48)->unique();
            $table->text('app_secret')->nullable();
            $table->text('token')->nullable();
            $table->text('encoding_aes_key')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('wechat_verification_files', function (Blueprint $table): void {
            $table->id();
            $table->string('filename', 100)->unique();
            $table->text('content');
            $table->foreignId('uploaded_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('wechat_payment_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 48)->unique();
            $table->text('api_v3_key')->nullable();
            $table->longText('merchant_private_key')->nullable();
            $table->longText('platform_certificate')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamps();
        });

        $this->upsertSetting('auth.wechat_mini', '微信小程序配置', [
            'driver' => 'local',
            'app_id' => '',
            'timeout_seconds' => 5,
        ]);
        $this->upsertSetting('auth.wechat_official_account', '微信公众号配置', [
            'driver' => 'local',
            'app_id' => '',
            'timeout_seconds' => 5,
        ]);
        $this->upsertSetting('payment.wechat', '微信支付配置', [
            'driver' => 'local',
            'merchant_id' => '',
            'merchant_certificate_serial' => '',
            'notify_url' => '',
            'refund_notify_url' => '',
            'timeout_seconds' => 8,
        ], [
            'driver', 'merchant_id', 'merchant_certificate_serial', 'notify_url',
            'refund_notify_url', 'timeout_seconds',
        ]);
        $this->upsertSetting('identity.verification', '实名认证配置', [
            'driver' => 'disabled',
            'collection_enabled' => false,
            'manual_review_enabled' => false,
        ], ['driver', 'collection_enabled', 'manual_review_enabled']);
    }

    public function down(): void
    {
        DB::table('system_setting_definitions')
            ->whereIn('setting_key', [
                'auth.wechat_mini', 'auth.wechat_official_account',
                'payment.wechat', 'identity.verification',
            ])
            ->delete();
        Schema::dropIfExists('wechat_payment_credentials');
        Schema::dropIfExists('wechat_verification_files');
        Schema::dropIfExists('wechat_provider_credentials');
    }

    /** @param array<string, mixed> $default */
    private function upsertSetting(string $key, string $name, array $default, ?array $requiredKeys = null): void
    {
        $now = now();
        DB::table('system_setting_definitions')->updateOrInsert(
            ['setting_key' => $key],
            [
                'group_code' => 'accounts',
                'name' => $name,
                'description' => '维护微信应用标识、运行模式和请求超时；敏感凭据通过专用加密存储管理。',
                'value_type' => 'object',
                'unit' => null,
                'options' => null,
                'validation_rules' => json_encode([
                    'required_keys' => $requiredKeys ?? ['driver', 'app_id', 'timeout_seconds'],
                    'properties' => [
                        'driver' => ['type' => 'string', 'enum' => ['local', 'wechat']],
                        'app_id' => ['type' => 'string', 'max_length' => 64],
                        'timeout_seconds' => ['type' => 'integer', 'min' => 1, 'max' => 30],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'default_value' => json_encode($default, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'risk_level' => 'normal',
                'enabled' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }
};
