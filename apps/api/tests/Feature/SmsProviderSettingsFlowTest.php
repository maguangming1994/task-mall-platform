<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\SmsProviderCredential;
use App\Modules\Auth\Services\AliyunSmsSender;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmsProviderSettingsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_response_is_flat_and_never_exposes_credentials(): void
    {
        config([
            'auth_tokens.phone_verification.driver' => 'aliyun',
            'auth_tokens.phone_verification.aliyun.sign_name' => 'Env Sign',
            'auth_tokens.phone_verification.aliyun.template_code' => 'SMS_ENV',
            'auth_tokens.phone_verification.aliyun.access_key_id' => 'ENV_ACCESS_KEY_ID',
            'auth_tokens.phone_verification.aliyun.access_key_secret' => 'ENV_ACCESS_KEY_SECRET',
        ]);
        $token = $this->adminToken();

        $response = $this->withToken($token)->getJson('/api/v1/admin/sms/settings')->assertOk();
        $response->assertJsonPath('data.driver', 'aliyun')
            ->assertJsonPath('data.access_key_id.configured', true)
            ->assertJsonPath('data.access_key_secret.configured', true)
            ->assertJsonMissingPath('data.aliyun')
            ->assertJsonMissingPath('data.access_key_id_source');
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ENV_ACCESS_KEY_ID', $encoded);
        $this->assertStringNotContainsString('ENV_ACCESS_KEY_SECRET', $encoded);
    }

    public function test_update_encrypts_credentials_and_clear_is_explicit(): void
    {
        $token = $this->adminToken();
        $this->verifyRecent($token);
        $payload = [
            'reason' => '配置阿里云短信服务',
            'driver' => 'aliyun',
            'region' => 'cn-hangzhou',
            'endpoint' => 'dysmsapi.aliyuncs.com',
            'sign_name' => 'Task Mall',
            'template_code' => 'SMS_123456',
            'verification_variables' => ['code', 'minutes'],
            'timeout_seconds' => 5,
            'ttl_seconds' => 300,
            'resend_interval_seconds' => 60,
            'hourly_limit' => 5,
            'daily_limit' => 50,
            'max_attempts' => 5,
            'access_key_id' => 'AKID_BACKEND_VALUE',
            'access_key_secret' => 'SECRET_BACKEND_VALUE',
            'security_token' => 'TOKEN_BACKEND_VALUE',
        ];

        $response = $this->withToken($token)->putJson('/api/v1/admin/sms/settings', $payload)->assertOk();
        $response->assertJsonPath('data.access_key_id.configured', true)
            ->assertJsonPath('data.access_key_secret.configured', true)
            ->assertJsonPath('data.security_token.configured', true);
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('AKID_BACKEND_VALUE', $encoded);
        $this->assertStringNotContainsString('SECRET_BACKEND_VALUE', $encoded);
        $this->assertStringNotContainsString('TOKEN_BACKEND_VALUE', $encoded);

        $rawCredential = (array) DB::table('sms_provider_credentials')->where('provider', 'aliyun')->first();
        $this->assertNotSame('AKID_BACKEND_VALUE', $rawCredential['access_key_id']);
        $this->assertNotSame('SECRET_BACKEND_VALUE', $rawCredential['access_key_secret']);
        $this->assertNotSame('TOKEN_BACKEND_VALUE', $rawCredential['security_token']);
        $rawVersion = DB::table('system_setting_versions')->latest('id')->value('value');
        $this->assertStringNotContainsString('AKID_BACKEND_VALUE', (string) $rawVersion);
        $this->assertStringNotContainsString('SECRET_BACKEND_VALUE', (string) $rawVersion);

        $this->withToken($token)->putJson('/api/v1/admin/sms/settings', [
            ...$payload,
            'access_key_id' => '',
            'access_key_secret' => '',
            'security_token' => '',
        ])->assertOk();
        $this->assertTrue(SmsProviderCredential::query()->where('provider', 'aliyun')->firstOrFail()->access_key_id === 'AKID_BACKEND_VALUE');

        $this->withToken($token)->putJson('/api/v1/admin/sms/settings', [
            'reason' => '清除短信服务凭据',
            'driver' => 'aliyun',
            'region' => 'cn-hangzhou',
            'endpoint' => 'dysmsapi.aliyuncs.com',
            'sign_name' => 'Task Mall',
            'template_code' => 'SMS_123456',
            'verification_variables' => ['code'],
            'timeout_seconds' => 5,
            'ttl_seconds' => 300,
            'resend_interval_seconds' => 60,
            'hourly_limit' => 5,
            'daily_limit' => 50,
            'max_attempts' => 5,
            'clear_access_key_id' => true,
            'clear_access_key_secret' => true,
            'clear_security_token' => true,
        ])->assertOk();
        $this->assertNull(SmsProviderCredential::query()->where('provider', 'aliyun')->firstOrFail()->access_key_id);
    }

    public function test_sms_test_returns_accepted_and_is_rate_limited(): void
    {
        $token = $this->adminToken();
        $this->verifyRecent($token);
        $this->mock(AliyunSmsSender::class)
            ->shouldReceive('send')
            ->times(5)
            ->andReturn(['request_id' => 'aliyun-request-1', 'biz_id' => 'aliyun-biz-1']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withToken($token)->postJson('/api/v1/admin/sms/settings/test', ['phone' => '13800139999'])
                ->assertOk()
                ->assertJsonPath('data.accepted', true)
                ->assertJsonPath('data.request_id', 'aliyun-request-1');
        }
        $this->withToken($token)->postJson('/api/v1/admin/sms/settings/test', ['phone' => '13800139999'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'PHONE_VERIFICATION_RATE_LIMITED');
    }

    private function adminToken(): string
    {
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'sms-root-'.uniqid(),
            'display_name' => 'SMS Root',
            'password' => 'AdminPassword12345!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $admin->account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }

    private function verifyRecent(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', [
            'password' => 'AdminPassword12345!',
        ])->assertOk();
    }
}
