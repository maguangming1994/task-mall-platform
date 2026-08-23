<?php

namespace Tests\Feature;

use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LegalDocumentSeeder::class);
    }

    public function test_user_ticket_returns_server_driven_chinese_workflow_and_capabilities(): void
    {
        $token = $this->loginAndConsent('13800138132');
        $ticket = $this->withToken($token)->postJson('/api/v1/support-tickets', [
            'category' => 'task', 'subject' => '任务审核结果咨询', 'content' => '请帮我查看第二步材料为什么未通过。',
        ])->assertCreated()
            ->assertJsonPath('data.workflow.status_label', '待客服受理')
            ->assertJsonPath('data.workflow.can_reply', true)
            ->json('data');

        $this->withToken($token)->postJson('/api/v1/support-tickets/'.$ticket['id'].'/messages', ['content' => '补充了完整截图。'])
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_service')
            ->assertJsonPath('data.workflow.status_label', '等待客服回复')
            ->assertJsonPath('data.workflow.can_close', true);

        $this->withToken($token)->postJson('/api/v1/support-tickets/'.$ticket['id'].'/close')
            ->assertOk()
            ->assertJsonPath('data.workflow.status_label', '工单已关闭')
            ->assertJsonPath('data.workflow.can_reply', false)
            ->assertJsonPath('data.workflow.can_close', false);
    }

    private function loginAndConsent(string $phone): string
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'support-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');
        foreach ($this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data') as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'support-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }

        return $token;
    }
}
