<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Services\PhoneVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_login_refresh_current_user_and_logout_form_a_complete_session_flow(): void
    {
        $headers = [
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'test-browser-device-001',
            'X-Device-Name' => 'PHPUnit Browser',
        ];

        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138000',
            'purpose' => 'login',
        ], $headers)
            ->assertAccepted()
            ->assertJsonPath('code', 'OK');

        $login = $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138000',
            'verification_code' => '246810',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.user.phone_masked', '138****8000')
            ->assertJsonPath('data.user.phone_bound', true);

        $accessToken = $login->json('data.access_token');
        $refreshToken = $login->json('data.refresh_token');

        $this->withToken($accessToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.nickname', '用户8000');

        $refresh = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertOk();

        $newAccessToken = $refresh->json('data.access_token');
        $this->withToken($accessToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_SESSION_REVOKED');

        $this->withToken($newAccessToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->withToken($newAccessToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_identities', 1);
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseCount('user_sessions', 2);
        $this->assertDatabaseMissing('users', ['phone' => '13800138000']);
    }

    public function test_login_requires_a_requested_and_valid_verification_code(): void
    {
        $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138001',
            'verification_code' => '246810',
        ], [
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'test-browser-device-002',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PHONE_VERIFICATION_FAILED');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_disabled_user_cannot_create_a_new_session(): void
    {
        $phoneHash = app(PhoneVerificationService::class)->phoneHash('13800138002');
        User::factory()->create([
            'phone' => '13800138002',
            'phone_hash' => $phoneHash,
            'phone_verified_at' => now(),
            'status' => 'disabled',
        ]);
        $headers = [
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'test-browser-device-003',
        ];

        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138002',
            'purpose' => 'login',
        ], $headers)->assertAccepted();

        $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138002',
            'verification_code' => '246810',
        ], $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_device_limit_revokes_the_oldest_device_and_its_sessions(): void
    {
        config(['auth_tokens.max_active_devices' => 2]);
        $firstAccessToken = null;

        foreach (range(1, 3) as $number) {
            $headers = [
                'X-Client-Platform' => 'h5-browser',
                'X-Device-Id' => 'limited-device-'.$number,
            ];
            $this->postJson('/api/v1/auth/phone/verification-codes', [
                'phone' => '13800138003',
                'purpose' => 'login',
            ], $headers)->assertAccepted();
            $login = $this->postJson('/api/v1/auth/phone/login', [
                'phone' => '13800138003',
                'verification_code' => '246810',
            ], $headers)->assertOk();
            $firstAccessToken ??= $login->json('data.access_token');
        }

        $this->withToken($firstAccessToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_SESSION_REVOKED');
        $this->assertDatabaseCount('user_devices', 3);
        $this->assertSame(2, UserDevice::query()->whereNull('revoked_at')->count());
    }
}
