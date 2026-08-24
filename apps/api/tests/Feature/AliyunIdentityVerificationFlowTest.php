<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Identity\Models\IdentityProviderCredential;
use App\Modules\Identity\Services\AliyunIdentityVerifier;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AliyunIdentityVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_admin_can_enable_aliyun_with_encrypted_credentials_that_are_never_returned(): void
    {
        $token = $this->adminToken('identity-settings-owner');
        $accessKeyId = 'LTAI5tIdentityExample';
        $accessKeySecret = 'aliyun-identity-secret-example-value';

        $response = $this->withToken($token)->putJson('/api/v1/admin/identity-verification/settings', [
            'driver' => 'aliyun',
            'collection_enabled' => true,
            'manual_review_enabled' => false,
            'aliyun' => ['region' => 'cn-hangzhou', 'endpoint' => 'cloudauth.aliyuncs.com', 'timeout_seconds' => 8],
            'access_key_id' => $accessKeyId,
            'access_key_secret' => $accessKeySecret,
            'reason' => '启用阿里云身份二要素核验服务。',
        ])->assertOk()
            ->assertJsonPath('data.driver', 'aliyun')
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.access_key_id.configured', true)
            ->assertJsonPath('data.access_key_secret.configured', true);

        $this->assertStringNotContainsString($accessKeyId, $response->getContent());
        $this->assertStringNotContainsString($accessKeySecret, $response->getContent());
        $record = IdentityProviderCredential::query()->where('provider', 'aliyun')->firstOrFail();
        $raw = DB::table('identity_provider_credentials')->where('provider', 'aliyun')->first();
        $this->assertSame($accessKeyId, $record->access_key_id);
        $this->assertSame($accessKeySecret, $record->access_key_secret);
        $this->assertNotSame($accessKeyId, $raw->access_key_id);
        $this->assertNotSame($accessKeySecret, $raw->access_key_secret);

        $auditPayload = DB::table('operation_logs')->latest('id')->value('after_value');
        $this->assertStringNotContainsString($accessKeyId, (string) $auditPayload);
        $this->assertStringNotContainsString($accessKeySecret, (string) $auditPayload);
    }

    public function test_user_identity_is_verified_without_persisting_raw_personal_information(): void
    {
        $this->enableAliyun();
        [$user, $token] = $this->loginUser('13800138101');
        $identityNumber = '11010519491231002X';
        $realName = '测试用户';
        $this->mock(AliyunIdentityVerifier::class)
            ->shouldReceive('verify')
            ->once()
            ->with($realName, $identityNumber)
            ->andReturn(['matched' => true, 'request_id' => 'aliyun-request-001', 'provider_code' => '1']);

        $response = $this->withToken($token)->postJson('/api/v1/me/identity-verification', [
            'real_name' => $realName,
            'identity_number' => $identityNumber,
            'personal_information_consent' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.real_name_masked', '测***')
            ->assertJsonPath('data.identity_number_masked', '110***********002X');

        $this->assertStringNotContainsString($realName, $response->getContent());
        $this->assertStringNotContainsString($identityNumber, $response->getContent());
        $row = DB::table('user_identity_verifications')->where('user_id', $user->id)->first();
        $serialized = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($realName, $serialized);
        $this->assertStringNotContainsString($identityNumber, $serialized);
        $this->assertSame('aliyun-request-001', $row->provider_request_id);
        $this->assertSame(64, strlen($row->identity_number_hash));
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.real_name_status', 'verified');
    }

    public function test_invalid_number_is_rejected_before_provider_and_provider_failure_is_generic(): void
    {
        $this->enableAliyun();
        [, $token] = $this->loginUser('13800138102');
        $mock = $this->mock(AliyunIdentityVerifier::class);
        $mock->shouldReceive('verify')->once()->andThrow(new RuntimeException('secret upstream detail'));

        $this->withToken($token)->postJson('/api/v1/me/identity-verification', [
            'real_name' => '测试用户',
            'identity_number' => '110105194912310021',
            'personal_information_consent' => true,
        ])->assertUnprocessable();

        $response = $this->withToken($token)->postJson('/api/v1/me/identity-verification', [
            'real_name' => '测试用户',
            'identity_number' => '11010519491231002X',
            'personal_information_consent' => true,
        ])->assertStatus(503)->assertJsonPath('code', 'IDENTITY_PROVIDER_UNAVAILABLE');
        $this->assertStringNotContainsString('secret upstream detail', $response->getContent());
        $this->assertDatabaseHas('user_identity_verifications', ['status' => 'provider_error', 'failure_code' => 'provider_unavailable']);
    }

    private function enableAliyun(): void
    {
        $token = $this->adminToken('identity-enable-'.str()->lower(str()->random(5)));
        $this->withToken($token)->putJson('/api/v1/admin/identity-verification/settings', [
            'driver' => 'aliyun', 'collection_enabled' => true, 'manual_review_enabled' => false,
            'aliyun' => ['region' => 'cn-hangzhou', 'endpoint' => 'cloudauth.aliyuncs.com', 'timeout_seconds' => 8],
            'access_key_id' => 'LTAI5tTestOnly', 'access_key_secret' => 'test-only-secret',
            'reason' => '测试启用阿里云实名认证服务。',
        ])->assertOk();
    }

    private function adminToken(string $account): string
    {
        $admin = AdminUser::query()->create([
            'account' => $account, 'display_name' => $account,
            'password' => 'AdminPassword12345!', 'status' => 'active',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', [
            'account' => $account, 'password' => 'AdminPassword12345!',
        ])->assertOk()->json('data.access_token');
    }

    /** @return array{User,string} */
    private function loginUser(string $phone): array
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'identity-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->assertOk()->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders([
                'Idempotency-Key' => "identity-consent-{$phone}-{$index}",
                'X-Client-Platform' => 'h5-browser',
            ])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept',
                'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return [User::query()->where('public_id', $login->json('data.user.id'))->firstOrFail(), $token];
    }
}
