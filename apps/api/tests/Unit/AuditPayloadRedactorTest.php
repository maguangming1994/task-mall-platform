<?php

namespace Tests\Unit;

use App\Modules\Audit\Services\AuditPayloadRedactor;
use PHPUnit\Framework\TestCase;

class AuditPayloadRedactorTest extends TestCase
{
    public function test_it_recursively_redacts_credentials_and_personal_information(): void
    {
        $payload = [
            'title' => '任务标题',
            'password' => 'never-return-this',
            'profile' => [
                'phone' => '13800138000',
                'email_address' => 'member@example.com',
                'delivery' => ['detailed_address' => '测试路 100 号'],
            ],
            'tokens' => [
                ['access_token' => 'access-secret', 'status' => 'active'],
            ],
        ];

        $redacted = (new AuditPayloadRedactor)->redact($payload);

        $this->assertSame('任务标题', $redacted['title']);
        $this->assertSame(AuditPayloadRedactor::REDACTED, $redacted['password']);
        $this->assertSame(AuditPayloadRedactor::REDACTED, $redacted['profile']['phone']);
        $this->assertSame(AuditPayloadRedactor::REDACTED, $redacted['profile']['email_address']);
        $this->assertSame(AuditPayloadRedactor::REDACTED, $redacted['profile']['delivery']['detailed_address']);
        $this->assertSame(AuditPayloadRedactor::REDACTED, $redacted['tokens'][0]['access_token']);
        $this->assertSame('active', $redacted['tokens'][0]['status']);
        $this->assertNull((new AuditPayloadRedactor)->redact(null));
    }
}
