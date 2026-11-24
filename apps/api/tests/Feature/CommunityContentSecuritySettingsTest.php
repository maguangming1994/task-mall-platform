<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Permission;
use App\Modules\Admin\Models\Role;
use App\Modules\Audit\Models\OperationLog;
use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Community\Models\CommunityContentSecuritySetting;
use App\Modules\Community\Services\AliyunContentSecurityClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CommunityContentSecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS_ENDPOINT = '/api/v1/admin/community/content-security/settings';

    private const TEST_ENDPOINT = '/api/v1/admin/community/content-security/settings/test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.green.access_key_id' => null, 'services.green.access_key_secret' => null]);
    }

    public function test_settings_require_authentication_permission_and_recent_verification(): void
    {
        $this->getJson(self::SETTINGS_ENDPOINT)->assertUnauthorized();
        $this->putJson(self::SETTINGS_ENDPOINT, $this->payload())->assertUnauthorized();
        $this->postJson(self::TEST_ENDPOINT, ['reason' => '测试内容安全配置。'])->assertUnauthorized();

        [, $communityToken] = $this->admin('security-community-reviewer', 'community_reviewer');
        $this->withToken($communityToken)->getJson(self::SETTINGS_ENDPOINT)
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');

        [, $token] = $this->settingsAdmin('security-settings-manager');
        $this->withToken($token)->getJson(self::SETTINGS_ENDPOINT)->assertOk()
            ->assertJsonPath('data.provider', 'wechat')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.aliyun.configured', false)
            ->assertJsonPath('data.aliyun.access_key_id_masked', null);

        AdminSession::query()->whereNull('revoked_at')->update(['verified_at' => now()->subHour()]);
        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_RECENT_VERIFICATION_REQUIRED');
        $this->verify($token);
        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, $this->payload('wechat'))
            ->assertOk()->assertJsonPath('data.provider', 'wechat');
        $this->assertDatabaseHas('operation_logs', [
            'action' => 'community.content_security.update',
            'subject_type' => 'community_content_security',
            'subject_id' => 'default',
        ]);
    }

    public function test_aliyun_credentials_are_encrypted_masked_preserved_cleared_and_never_audited_in_plaintext(): void
    {
        [, $token] = $this->settingsAdmin('security-credential-manager');
        $this->verify($token);
        $secrets = [
            'access_key_id' => 'LTAI-content-security-test-id',
            'access_key_secret' => 'content-security-test-access-key-secret',
        ];

        $response = $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->payload(), ...$secrets,
        ])->assertOk()
            ->assertJsonPath('data.provider', 'aliyun')
            ->assertJsonPath('data.aliyun.configured', true)
            ->assertJsonPath('data.aliyun.source', 'backend')
            ->assertJsonPath('data.aliyun.access_key_secret_masked', '********');
        $this->assertStringEndsWith(substr($secrets['access_key_id'], -4), $response->json('data.aliyun.access_key_id_masked'));
        $this->assertNoSecrets($response->getContent(), $secrets);

        $setting = CommunityContentSecuritySetting::query()->where('name', 'default')->firstOrFail();
        $raw = DB::table('community_content_security_settings')->where('name', 'default')->firstOrFail();
        $this->assertSame($secrets['access_key_id'], $setting->access_key_id);
        $this->assertSame($secrets['access_key_secret'], $setting->access_key_secret);
        $this->assertNotSame($secrets['access_key_id'], $raw->access_key_id);
        $this->assertNotSame($secrets['access_key_secret'], $raw->access_key_secret);
        $this->assertNoSecrets($this->withToken($token)->getJson(self::SETTINGS_ENDPOINT)->assertOk()->getContent(), $secrets);

        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->payload(),
            'access_key_id' => '',
            'access_key_secret' => '',
            'reason' => '更新服务码但保留现有内容安全凭证。',
        ])->assertOk()->assertJsonPath('data.aliyun.configured', true);
        $preserved = $setting->fresh();
        $this->assertSame($secrets['access_key_id'], $preserved->access_key_id);
        $this->assertSame($secrets['access_key_secret'], $preserved->access_key_secret);

        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->payload(),
            'clear_credentials' => true,
            'access_key_secret' => 'conflicting-new-secret',
        ])->assertUnprocessable();
        $this->assertSame($secrets['access_key_secret'], $setting->fresh()->access_key_secret);

        $clearResponse = $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->payload(),
            'clear_credentials' => true,
            'reason' => '清除已经撤销的阿里云内容安全凭证。',
        ])->assertOk()->assertJsonPath('data.aliyun.configured', false);
        $this->assertNull($setting->fresh()->access_key_id);
        $this->assertNull($setting->fresh()->access_key_secret);
        $this->assertNoSecrets($clearResponse->getContent(), $secrets);

        $auditPayload = json_encode(OperationLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertNoSecrets($auditPayload, $secrets);
    }

    public function test_aliyun_saved_configuration_test_is_audited_and_does_not_create_ugc_record(): void
    {
        [, $token] = $this->settingsAdmin('security-aliyun-test-manager');
        $this->saveAliyunConfiguration($token);
        $client = $this->mock(AliyunContentSecurityClient::class);
        $client->shouldReceive('scan')->once()->withArgs(
            function (array $configuration, string $content, string $dataId): bool {
                $this->assertSame('aliyun-test-access-key-id', $configuration['access_key_id']);
                $this->assertSame('校园学习经验分享。', $content);
                $this->assertStringStartsWith('settings-test-', $dataId);

                return true;
            },
        )->andReturn([
            'verdict' => 'pass',
            'risk_level' => 'none',
            'risk_labels' => [],
            'request_id' => 'settings-test-request',
            'response_time_ms' => 10,
        ]);

        $response = $this->withToken($token)->postJson(self::TEST_ENDPOINT, ['reason' => '验证已保存的阿里云配置可用。'])
            ->assertOk()
            ->assertJsonPath('data.last_test_status', 'success');
        $this->assertNotNull($response->json('data.last_tested_at'));
        $this->assertDatabaseCount('community_content_security_logs', 0);
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.content_security.test']);
    }

    public function test_failed_aliyun_configuration_test_returns_safe_error_and_records_failure(): void
    {
        [, $token] = $this->settingsAdmin('security-aliyun-failure-manager');
        $this->saveAliyunConfiguration($token);
        $client = $this->createMock(AliyunContentSecurityClient::class);
        $client->expects($this->once())->method('scan')->willThrowException(new RuntimeException('SDK simulated failure'));
        $this->app->instance(AliyunContentSecurityClient::class, $client);

        $response = $this->withToken($token)->postJson(self::TEST_ENDPOINT, ['reason' => '验证失败配置会返回可操作提示。'])
            ->assertUnprocessable()->assertJsonPath('code', 'CONTENT_SECURITY_TEST_FAILED');
        $this->assertStringNotContainsString('SDK simulated failure', $response->getContent());
        $this->withToken($token)->getJson(self::SETTINGS_ENDPOINT)->assertOk()
            ->assertJsonPath('data.last_test_status', 'failed');
        $this->assertDatabaseCount('community_content_security_logs', 0);
        $this->assertDatabaseHas('operation_logs', ['action' => 'community.content_security.test_failed']);
    }

    public function test_wechat_test_explicitly_reports_credential_only_result(): void
    {
        [, $token] = $this->settingsAdmin('security-wechat-test-manager');
        $this->verify($token);
        $tokens = $this->createMock(WechatAccessTokenService::class);
        $tokens->expects($this->once())->method('token')->with('mini')->willReturn('fake-access-token');
        $this->app->instance(WechatAccessTokenService::class, $tokens);

        $this->withToken($token)->postJson(self::TEST_ENDPOINT, ['reason' => '仅校验微信小程序访问凭证。'])
            ->assertOk()
            ->assertJsonPath('data.last_test_status', 'credential_only')
            ->assertJsonPath('data.provider', 'wechat');
        $this->assertDatabaseCount('community_content_security_logs', 0);
    }

    /** @return array<string,mixed> */
    private function payload(string $provider = 'aliyun'): array
    {
        return [
            'reason' => '配置社区内容安全服务商并保留审计。',
            'enabled' => true,
            'provider' => $provider,
            'region' => 'cn-shanghai',
            'endpoint' => 'green-cip.cn-shanghai.aliyuncs.com',
            'service' => 'ugc_moderation_byllm',
        ];
    }

    /** @return array{AdminUser,string} */
    private function admin(string $account, string $roleCode): array
    {
        $admin = AdminUser::query()->create([
            'account' => $account,
            'display_name' => $account,
            'password' => 'AdminPassword12345!',
            'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    /** @return array{AdminUser,string} */
    private function settingsAdmin(string $account): array
    {
        $role = Role::query()->create([
            'code' => $account.'_role',
            'name' => '内容安全配置管理员',
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
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account,
            'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');

        return [$admin, $token];
    }

    private function verify(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/admin/auth/verify', ['password' => 'AdminPassword12345!'])->assertOk();
    }

    private function saveAliyunConfiguration(string $token): void
    {
        $this->verify($token);
        $this->withToken($token)->putJson(self::SETTINGS_ENDPOINT, [
            ...$this->payload(),
            'access_key_id' => 'aliyun-test-access-key-id',
            'access_key_secret' => 'aliyun-test-access-key-secret',
        ])->assertOk();
    }

    /** @param array<string,string> $secrets */
    private function assertNoSecrets(string $content, array $secrets): void
    {
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }
}
