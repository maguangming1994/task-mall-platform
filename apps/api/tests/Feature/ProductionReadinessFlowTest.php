<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Auth\Services\SmsProviderSettingsService;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Operations\Services\ProductionReadinessService;
use App\Modules\Payment\Services\WechatPaymentSettingsService;
use App\Modules\Upload\Models\UploadedFile;
use App\Modules\Upload\Services\FileScanService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionReadinessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_readiness_report_lists_actions_without_exposing_secrets(): void
    {
        $token = $this->adminToken();
        config([
            'wechat_auth.mini.app_secret' => 'never-return-this-mini-secret',
            'auth_tokens.phone_verification.webhook.token' => 'never-return-this-sms-token',
        ]);
        $response = $this->withToken($token)->getJson('/api/v1/admin/production-readiness')
            ->assertOk()->assertJsonPath('data.ready', false);
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('never-return-this-mini-secret', $encoded);
        $this->assertStringNotContainsString('never-return-this-sms-token', $encoded);
        $this->artisan('production:readiness --strict')->assertExitCode(1);
    }

    public function test_complete_target_configuration_is_reported_ready(): void
    {
        $originalDatabase = config('database.default');
        $originalEnvironment = app()->environment();
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false, 'app.url' => 'https://api.example.test',
            'auth_tokens.jwt_secret' => str_repeat('u', 40), 'auth_tokens.identity_hash_secret' => str_repeat('i', 40),
            'admin_auth.jwt_secret' => str_repeat('a', 40), 'admin_auth.hash_secret' => str_repeat('h', 40),
            'cors.allowed_origins' => ['https://app.example.test', 'https://admin.example.test'], 'monitoring.metrics_token' => str_repeat('m', 40),
            'database.default' => 'pgsql', 'cache.default' => 'redis', 'queue.default' => 'redis',
            'wechat_auth.driver' => 'wechat', 'wechat_auth.mini.app_id' => 'mini-app', 'wechat_auth.mini.app_secret' => 'mini-secret',
            'wechat_auth.official_account.app_id' => 'oa-app', 'wechat_auth.official_account.app_secret' => 'oa-secret',
            'production_readiness.authorized_domains' => ['example.test'],
            'wechat_payment.driver' => 'wechat', 'wechat_payment.merchant_id' => 'merchant-1',
            'wechat_payment.merchant_certificate_serial' => 'certificate-serial', 'wechat_payment.merchant_private_key_path' => '/secure/apiclient_key.pem',
            'wechat_payment.api_v3_key' => str_repeat('p', 32), 'wechat_payment.platform_certificate_path' => __FILE__,
            'wechat_payment.notify_url' => 'https://api.example.test/api/v1/payment-notifications/wechat',
            'auth_tokens.phone_verification.driver' => 'webhook', 'auth_tokens.phone_verification.webhook.endpoint' => 'https://sms.example.test/send',
            'auth_tokens.phone_verification.webhook.token' => 'sms-token', 'auth_tokens.phone_verification.webhook.template_id' => 'template-1',
            'auth_tokens.phone_verification.webhook.sign_name' => 'Task Mall',
            'notifications.driver' => 'webhook', 'notifications.webhook.endpoint' => 'https://messages.example.test/send', 'notifications.webhook.token' => str_repeat('n', 40),
            'uploads.mode' => 's3_presigned', 'uploads.disk' => 's3', 'uploads.scanner' => 'webhook',
            'uploads.scanner_webhook.endpoint' => 'https://scan.example.test/scan', 'uploads.scanner_webhook.token' => 'scan-token',
            'production_readiness.operator_entity_name' => 'Example Entity', 'production_readiness.customer_service_contact' => 'service@example.test',
            'production_readiness.privacy_contact' => 'privacy@example.test', 'production_readiness.legal_documents_approved' => true,
        ]);
        $this->mock(WechatProviderSettingsService::class)
            ->shouldReceive('effective')->twice()->andReturnUsing(fn (string $provider) => [
                'driver' => 'wechat', 'app_id' => $provider.'-app', 'app_secret' => $provider.'-secret',
            ]);
        $this->mock(WechatPaymentSettingsService::class)
            ->shouldReceive('effective')->once()->andReturn([
                'driver' => 'wechat', 'merchant_id' => 'merchant-1',
                'merchant_certificate_serial' => 'certificate-serial', 'merchant_private_key' => null,
                'api_v3_key' => str_repeat('p', 32), 'platform_certificate' => null,
                'notify_url' => 'https://api.example.test/api/v1/payment-notifications/wechat',
            ]);
        $this->mock(SmsProviderSettingsService::class)
            ->shouldReceive('effective')->twice()->andReturn([
                'driver' => 'webhook',
                'webhook' => [
                    'endpoint' => 'https://sms.example.test/send', 'token' => 'sms-token',
                    'template_id' => 'template-1', 'sign_name' => 'Task Mall',
                ],
            ]);
        try {
            $result = app(ProductionReadinessService::class)->evaluate();
            $this->assertTrue($result['ready']);
            $this->assertSame(0, $result['summary']['failed']);
        } finally {
            config(['database.default' => $originalDatabase]);
            $this->app->detectEnvironment(fn () => $originalEnvironment);
        }
    }

    public function test_sms_and_file_scanner_webhooks_fail_closed_and_accept_valid_provider_results(): void
    {
        Storage::fake('s3');
        config([
            'auth_tokens.phone_verification.driver' => 'webhook',
            'auth_tokens.phone_verification.webhook.endpoint' => 'https://sms.example.test/send',
            'auth_tokens.phone_verification.webhook.token' => 'sms-token',
            'uploads.scanner' => 'webhook', 'uploads.scanner_webhook.endpoint' => 'https://scan.example.test/scan',
            'uploads.scanner_webhook.token' => 'scan-token',
        ]);
        $issuedCode = null;
        Http::fake(function (Request $request) use (&$issuedCode) {
            if (str_contains($request->url(), 'sms.example.test')) {
                $issuedCode = $request->data()['verification_code'];

                return Http::response(['accepted' => true]);
            }
            if (($request->data()['filename'] ?? null) === 'failed.png') {
                return Http::response([], 503);
            }

            return Http::response(['clean' => true, 'detected_mime' => 'image/png', 'sha256' => str_repeat('a', 64)]);
        });
        $phones = app(PhoneVerificationService::class);
        $phones->issue('13800138061', 'login');
        $this->assertIsString($issuedCode);
        $this->assertTrue($phones->verify('13800138061', 'login', $issuedCode));

        Storage::disk('s3')->put('quarantine/provider/image.png', "\x89PNG\r\n\x1a\n");
        $user = User::query()->create(['nickname' => 'Scanner Owner', 'status' => 'active']);
        $file = UploadedFile::query()->create([
            'owner_user_id' => $user->id, 'purpose' => 'avatar', 'original_name' => 'image.png', 'extension' => 'png', 'declared_mime' => 'image/png',
            'declared_size' => 8, 'actual_size' => 8, 'disk' => 's3', 'quarantine_path' => 'quarantine/provider/image.png',
            'status' => 'scanning', 'idempotency_key_hash' => hash('sha256', 'provider-scan-test'), 'ticket_expires_at' => now()->addHour(),
        ]);
        app(FileScanService::class)->scan($file);
        $this->assertSame('clean', $file->fresh()->status);

        $failedFile = $file->replicate(['public_id', 'sha256', 'detected_mime', 'scanned_at']);
        $failedFile->idempotency_key_hash = hash('sha256', 'provider-scan-failure-test');
        $failedFile->original_name = 'failed.png';
        $failedFile->quarantine_path = 'quarantine/provider/failed.png';
        $failedFile->status = 'scanning';
        Storage::disk('s3')->put($failedFile->quarantine_path, "\x89PNG\r\n\x1a\n");
        $failedFile->save();
        app(FileScanService::class)->scan($failedFile);
        $this->assertSame('rejected', $failedFile->fresh()->status);
        $this->assertSame('external_scan_invalid_response', $failedFile->rejection_code);
    }

    private function adminToken(): string
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create(['account' => 'readiness-root', 'display_name' => 'Readiness Root', 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));

        return $this->postJson('/api/v1/admin/auth/login', ['account' => 'readiness-root', 'password' => 'AdminPassword12345!'])
            ->assertOk()->json('data.access_token');
    }
}
