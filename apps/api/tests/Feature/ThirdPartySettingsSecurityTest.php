<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\WechatProviderCredential;
use App\Modules\Auth\Services\WechatIdentityProvider;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Payment\Models\WechatPaymentCredential;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThirdPartySettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_wechat_credentials_are_encrypted_hidden_and_used_at_runtime(): void
    {
        $token = $this->loginAsSuperAdmin('wechat-settings-owner');
        $secret = 'wechat-mini-secret-value';

        $response = $this->withToken($token)->putJson('/api/v1/admin/wechat/settings/mini', [
            'driver' => 'wechat',
            'app_id' => 'wx1234567890abcdef',
            'timeout_seconds' => 7,
            'app_secret' => $secret,
            'reason' => '配置正式微信小程序登录参数。',
        ])->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.app_secret.configured', true)
            ->assertJsonPath('data.app_secret.source', 'backend');
        $this->assertStringNotContainsString($secret, $response->getContent());

        $record = WechatProviderCredential::query()->where('provider', 'mini')->firstOrFail();
        $raw = DB::table('wechat_provider_credentials')->where('provider', 'mini')->first();
        $this->assertSame($secret, $record->app_secret);
        $this->assertNotSame($secret, $raw->app_secret);
        $effective = app(WechatProviderSettingsService::class)->effective('mini');
        $this->assertSame('wx1234567890abcdef', $effective['app_id']);
        $this->assertSame($secret, $effective['app_secret']);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response([
                'access_token' => 'saved-settings-access-token',
                'expires_in' => 7200,
            ]),
            'api.weixin.qq.com/wxa/business/getuserphonenumber*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'phone_info' => ['phoneNumber' => '13800138883'],
            ]),
        ]);
        $this->assertSame(
            '13800138883',
            app(WechatIdentityProvider::class)->exchangePhoneNumber('saved-settings-phone-code'),
        );
    }

    public function test_official_account_verification_file_is_restricted_and_available_at_root(): void
    {
        $token = $this->loginAsSuperAdmin('wechat-file-owner');
        $filename = 'MP_verify_AbCdEf123456.txt';
        $content = 'AbCdEf123456Verification';

        $this->withToken($token)->post('/api/v1/admin/wechat/verification-files', [
            'file' => UploadedFile::fake()->createWithContent($filename, $content),
        ])->assertCreated()->assertJsonPath('data.filename', $filename);

        $this->get('/'.$filename)->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeText($content);

        $this->withToken($token)->post('/api/v1/admin/wechat/verification-files', [
            'file' => UploadedFile::fake()->createWithContent('../index.php', '<?php'),
        ])->assertUnprocessable();
    }

    public function test_payment_keys_and_certificates_are_encrypted_and_identity_mode_is_specialized(): void
    {
        $token = $this->loginAsSuperAdmin('third-party-settings-owner');
        [$privateKey, $certificate] = $this->certificatePair();
        $this->assertGreaterThan(1000, strlen($privateKey));
        $this->assertGreaterThan(500, strlen($certificate));
        $apiV3Key = '12345678901234567890123456789012';

        $response = $this->withToken($token)->post('/api/v1/admin/wechat-payment/credentials', [
            'reason' => '上传微信支付正式密钥与证书。',
            'api_v3_key' => $apiV3Key,
            'merchant_private_key' => UploadedFile::fake()->createWithContent('apiclient_key.pem', $privateKey),
            'platform_certificate' => UploadedFile::fake()->createWithContent('wechatpay.pem', $certificate),
        ])->assertOk()
            ->assertJsonPath('data.api_v3_key.configured', true)
            ->assertJsonPath('data.merchant_private_key.configured', true)
            ->assertJsonPath('data.platform_certificate.configured', true);
        $this->assertStringNotContainsString($apiV3Key, $response->getContent());
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $response->getContent());

        $raw = DB::table('wechat_payment_credentials')->where('provider', 'wechat_pay_v3')->first();
        $record = WechatPaymentCredential::query()->where('provider', 'wechat_pay_v3')->firstOrFail();
        $this->assertNotSame($apiV3Key, $raw->api_v3_key);
        $this->assertSame($apiV3Key, $record->api_v3_key);
        $this->assertSame(trim($privateKey), $record->merchant_private_key);

        $this->withToken($token)->putJson('/api/v1/admin/identity-verification/settings', [
            'driver' => 'manual', 'collection_enabled' => true, 'manual_review_enabled' => true,
            'reason' => '启用人工实名认证审核流程。',
        ])->assertOk()->assertJsonPath('data.driver', 'manual');

        $keys = $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk()->json('data.items');
        $this->assertNotContains('payment.wechat', array_column($keys, 'key'));
        $this->assertNotContains('identity.verification', array_column($keys, 'key'));
    }

    /** @return array{string,string} */
    private function certificatePair(): array
    {
        $opensslConfig = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (is_file($opensslConfig)) {
            $options['config'] = $opensslConfig;
        }
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $privateKey, null, $options);
        $csr = openssl_csr_new(['commonName' => 'task-mall.test'], $key, [...$options, 'digest_alg' => 'sha256']);
        $certificateResource = openssl_csr_sign($csr, null, $key, 30, [...$options, 'digest_alg' => 'sha256']);
        openssl_x509_export($certificateResource, $certificate);

        return [$privateKey, $certificate];
    }

    private function loginAsSuperAdmin(string $account): string
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
}
