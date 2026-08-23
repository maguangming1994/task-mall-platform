<?php

namespace Tests\Feature;

use App\Modules\Auth\Services\AliyunSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AliyunPhoneVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'X-Client-Platform' => 'h5-browser',
        'X-Device-Id' => 'aliyun-sms-test-device',
    ];

    public function test_aliyun_accepted_code_can_complete_phone_login(): void
    {
        config([
            'auth_tokens.phone_verification.driver' => 'aliyun',
            'auth_tokens.phone_verification.resend_interval_seconds' => 0,
        ]);
        $this->mock(AliyunSmsSender::class)
            ->shouldReceive('send')
            ->once()
            ->with('13800138100', 'login', '246810');

        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138100',
            'purpose' => 'login',
        ], self::HEADERS)
            ->assertAccepted()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('message', '验证码已发送，请注意查收。');

        $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138100',
            'verification_code' => '246810',
        ], self::HEADERS)
            ->assertOk()
            ->assertJsonPath('code', 'OK');
    }

    public function test_provider_failure_does_not_leave_a_usable_code(): void
    {
        config([
            'auth_tokens.phone_verification.driver' => 'aliyun',
            'auth_tokens.phone_verification.resend_interval_seconds' => 0,
        ]);
        $this->mock(AliyunSmsSender::class)
            ->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('provider unavailable'));

        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138101',
            'purpose' => 'login',
        ], self::HEADERS)
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'PHONE_VERIFICATION_PROVIDER_UNAVAILABLE');

        $this->postJson('/api/v1/auth/phone/login', [
            'phone' => '13800138101',
            'verification_code' => '246810',
        ], self::HEADERS)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PHONE_VERIFICATION_FAILED');
    }

    public function test_repeated_requests_return_a_chinese_rate_limit_error(): void
    {
        config([
            'auth_tokens.phone_verification.driver' => 'log',
            'auth_tokens.phone_verification.resend_interval_seconds' => 60,
        ]);

        $payload = [
            'phone' => '13800138102',
            'purpose' => 'login',
        ];
        $this->postJson('/api/v1/auth/phone/verification-codes', $payload, self::HEADERS)
            ->assertAccepted();

        $this->postJson('/api/v1/auth/phone/verification-codes', $payload, self::HEADERS)
            ->assertStatus(429)
            ->assertJsonPath('code', 'PHONE_VERIFICATION_RATE_LIMITED')
            ->assertJsonPath('message', '验证码发送过于频繁，请稍后再试。')
            ->assertJsonPath('details.retry_after_seconds', 60);
    }
}
