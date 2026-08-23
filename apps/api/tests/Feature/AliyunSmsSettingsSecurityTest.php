<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use App\Modules\Audit\Models\OperationLog;
use App\Modules\Auth\Models\SmsProviderCredential;
use App\Modules\Auth\Services\AliyunSmsSender;
use App\Modules\Auth\Services\SmsProviderSettingsService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AliyunSmsSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS_ENDPOINT = '/api/v1/admin/sms/settings';

    private const TEST_ENDPOINT = '/api/v1/admin/sms/settings/test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_unauthorized_clients_cannot_read_write_or_test_sms_settings(): void
    {
        $this->getJson(self::SETTINGS_ENDPOINT)->assertUnauthorized();
        $this->putJson(self::SETTINGS_ENDPOINT, $this->settingsPayload())->assertUnauthorized();
        $this->postJson(self::TEST_ENDPOINT, ['phone' => '13800138000'])->assertUnauthorized();
    }

    public function test_non_super_admin_with_settings_permission_cannot_read_write_or_test_sms_settings(): void
    {
        $token = $this->loginAsSettingsManager('sms-settings-operator');

        $this->withToken($token)->getJson(self::SETTINGS_ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, $this->settingsPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $this->withToken($token)->postJson(self::TEST_ENDPOINT, ['phone' => '13800138000'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');

        $this->assertDatabaseCount('sms_provider_credentials', 0);
    }

    public function test_super_admin_update_encrypts_credentials_and_get_does_not_leak_them(): void
    {
        $token = $this->loginAsSuperAdmin('sms-settings-security-owner');
        $secrets = [
            'access_key_id' => 'LTAI-security-test-id',
            'access_key_secret' => 'security-test-access-key-secret',
            'security_token' => 'security-test-sts-token',
        ];

        $response = $this->withToken($token)->putJson(
            self::SETTINGS_ENDPOINT,
            [...$this->settingsPayload(), ...$secrets],
        );
        $response->assertOk()
            ->assertJsonPath('data.access_key_id.configured', true)
            ->assertJsonPath('data.access_key_secret.configured', true)
            ->assertJsonPath('data.security_token.configured', true)
            ->assertJsonMissing(['access_key_id' => $secrets['access_key_id']])
            ->assertJsonMissing(['access_key_secret' => $secrets['access_key_secret']])
            ->assertJsonMissing(['security_token' => $secrets['security_token']]);
        $this->assertNoSensitiveValueInResponse($response, $secrets);

        $credential = SmsProviderCredential::query()->where('provider', 'aliyun')->firstOrFail();
        $raw = DB::table('sms_provider_credentials')->where('provider', 'aliyun')->first();
        foreach ($secrets as $field => $value) {
            $this->assertSame($value, $credential->{$field});
            $this->assertNotSame($value, $raw->{$field});
        }

        $getResponse = $this->withToken($token)->getJson(self::SETTINGS_ENDPOINT)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'access_key_id' => ['configured', 'masked'],
                    'access_key_secret' => ['configured', 'masked'],
                    'security_token' => ['configured', 'masked'],
                ],
            ]);
        $this->assertNoSensitiveValueInResponse($getResponse, $secrets);

        $auditPayload = json_encode(OperationLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        foreach ($secrets as $value) {
            $this->assertStringNotContainsString($value, $auditPayload);
        }
        $this->assertDatabaseHas('operation_logs', [
            'subject_type' => 'sms_provider',
            'subject_id' => 'aliyun',
        ]);
    }

    public function test_empty_secret_values_preserve_existing_credentials_and_clear_flags_remove_them(): void
    {
        $token = $this->loginAsSuperAdmin('sms-settings-preserve-owner');
        $initial = [
            'access_key_id' => 'LTAI-preserve-id',
            'access_key_secret' => 'preserve-access-key-secret',
            'security_token' => 'preserve-security-token',
        ];

        $this->withToken($token)->putJson(
            self::SETTINGS_ENDPOINT,
            [...$this->settingsPayload(), ...$initial],
        )->assertOk();

        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->settingsPayload(),
            'access_key_secret' => '',
            'security_token' => '',
            'sign_name' => 'Updated Sign',
            'reason' => '更新签名但保留现有短信凭据。',
        ])->assertOk();

        $preserved = SmsProviderCredential::query()->where('provider', 'aliyun')->firstOrFail();
        $this->assertSame($initial['access_key_id'], $preserved->access_key_id);
        $this->assertSame($initial['access_key_secret'], $preserved->access_key_secret);
        $this->assertSame($initial['security_token'], $preserved->security_token);

        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->settingsPayload(),
            'clear_access_key_id' => true,
            'clear_access_key_secret' => true,
            'clear_security_token' => true,
            'reason' => '清除已撤销的阿里云短信凭据。',
        ])->assertOk();

        $cleared = SmsProviderCredential::query()->where('provider', 'aliyun')->firstOrFail();
        $this->assertNull($cleared->access_key_id);
        $this->assertNull($cleared->access_key_secret);
        $this->assertNull($cleared->security_token);
        $this->withToken($token)->getJson(self::SETTINGS_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.access_key_id.configured', false)
            ->assertJsonPath('data.access_key_secret.configured', false)
            ->assertJsonPath('data.security_token.configured', false);
    }

    public function test_backend_credentials_override_env_and_missing_backend_fields_fall_back_to_env(): void
    {
        config([
            'auth_tokens.phone_verification.aliyun.access_key_id' => 'env-access-key-id',
            'auth_tokens.phone_verification.aliyun.access_key_secret' => 'env-access-key-secret',
            'auth_tokens.phone_verification.aliyun.security_token' => 'env-security-token',
        ]);

        $settings = app(SmsProviderSettingsService::class);
        $this->assertSame([
            'access_key_id' => 'env-access-key-id',
            'access_key_secret' => 'env-access-key-secret',
            'security_token' => 'env-security-token',
        ], $settings->aliyunCredentials());

        $token = $this->loginAsSuperAdmin('sms-settings-priority-owner');
        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->settingsPayload(),
            'access_key_id' => 'backend-access-key-id',
            'access_key_secret' => 'backend-access-key-secret',
            'reason' => '验证后台短信凭据优先且保留环境兜底。',
        ])->assertOk();

        $this->assertSame([
            'access_key_id' => 'backend-access-key-id',
            'access_key_secret' => 'backend-access-key-secret',
            'security_token' => 'env-security-token',
        ], app(SmsProviderSettingsService::class)->aliyunCredentials());
    }

    public function test_test_send_is_rate_limited_and_audited_without_sensitive_values(): void
    {
        $token = $this->loginAsSuperAdmin('sms-settings-test-owner');
        $phone = '13800138001';
        $this->mock(AliyunSmsSender::class)
            ->shouldReceive('send')
            ->times(5)
            ->with($phone, 'admin_test', '246810');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->withToken($token)->postJson(self::TEST_ENDPOINT, ['phone' => $phone])
                ->assertOk()
                ->assertJsonPath('data.accepted', true)
                ->assertJsonPath('data.message', '阿里云短信测试请求已提交。');
            $this->assertNotNull($response->json('data.sent_at'));
        }

        $this->withToken($token)->postJson(self::TEST_ENDPOINT, ['phone' => $phone])
            ->assertStatus(429);

        $logs = OperationLog::query()->where('subject_type', 'sms_provider')->get();
        $this->assertGreaterThanOrEqual(5, $logs->count());
        $serialized = json_encode($logs->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($phone, $serialized);
        $this->assertStringNotContainsString('246810', $serialized);
        $this->assertStringNotContainsString('access-key', $serialized);
        $this->assertStringNotContainsString('security-token', $serialized);
    }

    /** @return array<string,mixed> */
    private function settingsPayload(): array
    {
        return [
            'driver' => 'aliyun',
            'region' => 'cn-hangzhou',
            'endpoint' => 'dysmsapi.aliyuncs.com',
            'sign_name' => 'Task Mall',
            'template_code' => 'SMS_123456789',
            'verification_variables' => ['code', 'minutes'],
            'timeout_seconds' => 5,
            'ttl_seconds' => 300,
            'resend_interval_seconds' => 60,
            'hourly_limit' => 5,
            'daily_limit' => 10,
            'max_attempts' => 5,
            'reason' => '配置阿里云短信验证码服务并保留完整审计。',
        ];
    }

    private function loginAsSuperAdmin(string $account): string
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }

    private function loginAsSettingsManager(string $account): string
    {
        $role = Role::query()->create([
            'code' => $account.'_role',
            'name' => '短信配置普通管理员',
            'system_role' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'settings.manage')->value('id'));
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach($role->id);

        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }

    /** @param array<string,string> $sensitiveValues */
    private function assertNoSensitiveValueInResponse(mixed $response, array $sensitiveValues): void
    {
        $content = $response->getContent();
        foreach ($sensitiveValues as $value) {
            $this->assertStringNotContainsString($value, $content);
        }
    }
}
