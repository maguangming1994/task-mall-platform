<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Models\UserLegalConsent;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class LegalConsentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_database_seeder_creates_complete_local_placeholder_documents(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('legal_document_versions', 2);
        $this->assertSame(0, LegalDocumentVersion::query()->whereNull('public_id')->count());
        $this->assertSame(0, LegalDocumentVersion::query()->whereNull('content_hash')->count());
    }

    public function test_current_documents_pending_consents_and_idempotent_acceptance(): void
    {
        $this->seed(LegalDocumentSeeder::class);
        $login = $this->wechatLogin('legal-open-1');
        $token = $login->json('data.access_token');

        $this->getJson('/api/v1/legal-documents', $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $pending = $this->withToken($token)
            ->getJson('/api/v1/me/legal-consents/pending?scope=account')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($pending->json('data') as $index => $document) {
            $idempotencyKey = 'legal-accept-'.$index.'-0001';
            $this->recordConsent($token, $document['id'], 'accept', $idempotencyKey)->assertCreated();
            $this->recordConsent($token, $document['id'], 'accept', $idempotencyKey)->assertCreated();
        }

        $this->withToken($token)
            ->getJson('/api/v1/me/legal-consents/pending?scope=account')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('user_legal_consents', 2);
    }

    public function test_phone_binding_is_blocked_until_required_documents_are_accepted(): void
    {
        $this->seed(LegalDocumentSeeder::class);
        $token = $this->wechatLogin('legal-open-2')->json('data.access_token');

        $this->withToken($token)
            ->putJson('/api/v1/me/phone', [
                'new_phone' => '13800138770',
                'new_phone_verification_code' => '246810',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'LEGAL_DOCUMENT_CONSENT_REQUIRED')
            ->assertJsonCount(2, 'details.documents');

        $this->acceptAllPending($token);
        $this->postJson('/api/v1/auth/phone/verification-codes', [
            'phone' => '13800138770',
            'purpose' => 'bind_phone',
        ], $this->headers())->assertAccepted();

        $this->withToken($token)
            ->putJson('/api/v1/me/phone', [
                'new_phone' => '13800138770',
                'new_phone_verification_code' => '246810',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_bound', true);
    }

    public function test_withdrawal_requires_explicit_acknowledgement_and_reactivates_gate(): void
    {
        $this->seed(LegalDocumentSeeder::class);
        $token = $this->wechatLogin('legal-open-3')->json('data.access_token');
        $this->acceptAllPending($token);
        $document = LegalDocumentVersion::query()->where('document_type', 'privacy_policy')->firstOrFail();

        $this->recordConsent($token, $document->public_id, 'withdraw', 'withdraw-no-ack-001')
            ->assertConflict()
            ->assertJsonPath('code', 'LEGAL_CONSENT_WITHDRAWAL_RESTRICTS_SERVICE');
        $this->recordConsent($token, $document->public_id, 'withdraw', 'withdraw-with-ack-01', true)
            ->assertCreated();

        $this->withToken($token)
            ->getJson('/api/v1/me/legal-consents/pending?scope=account')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_type', 'privacy_policy');
    }

    public function test_new_effective_version_requires_fresh_acceptance_and_consent_is_append_only(): void
    {
        $this->seed(LegalDocumentSeeder::class);
        $token = $this->wechatLogin('legal-open-4')->json('data.access_token');
        $this->acceptAllPending($token);

        LegalDocumentVersion::query()->create([
            'document_type' => 'user_agreement',
            'version' => 'dev-2.0',
            'title' => '开发环境用户协议第二版（不可用于生产）',
            'content' => '这是用于验证重新同意机制的开发测试协议正文，不得用于生产。',
            'content_format' => 'markdown',
            'consent_required' => true,
            'withdrawal_allowed' => true,
            'status' => 'published',
            'effective_at' => now(),
            'published_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/me/legal-consents/pending?scope=account')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', 'dev-2.0');

        $consent = UserLegalConsent::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $consent->update(['action' => 'withdraw']);
    }

    public function test_super_admin_can_publish_an_immutable_document_version_with_audit(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create([
            'account' => 'legal-admin',
            'display_name' => '协议管理员',
            'password' => 'LegalAdminPassword123!',
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $token = $this->postJson('/api/v1/admin/auth/login', [
            'account' => 'legal-admin',
            'password' => 'LegalAdminPassword123!',
        ])->assertOk()->json('data.access_token');

        $definition = [
            'document_type' => 'task_rules',
            'version' => 'reviewed-1.0',
            'title' => '任务参与规则',
            'content_format' => 'rich_text_html',
            'content' => '<h2>任务参与规则</h2><p>这是已经由授权人员提交的任务参与规则测试正文，长度满足草稿校验。</p>',
            'consent_required' => true,
            'withdrawal_allowed' => true,
            'effective_at' => now()->addDay()->toIso8601String(),
            'change_summary' => '建立第一版任务参与规则。',
        ];
        $draft = $this->withToken($token)->postJson('/api/v1/admin/legal-documents', $definition)->assertCreated();

        $documentId = $draft->json('data.id');
        $definition['title'] = '任务参与规则（审核稿）';
        $definition['change_summary'] = '完成第一版任务参与规则审核。';
        $this->withToken($token)->putJson("/api/v1/admin/legal-documents/{$documentId}", $definition)
            ->assertOk()
            ->assertJsonPath('data.title', '任务参与规则（审核稿）');

        $this->withToken($token)->postJson("/api/v1/admin/legal-documents/{$documentId}/publish", [
            'confirm_legal_review' => true,
            'reason' => '已完成授权审核，安排未来生效。',
        ])->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->withToken($token)->putJson("/api/v1/admin/legal-documents/{$documentId}", $definition)
            ->assertConflict()
            ->assertJsonPath('code', 'LEGAL_DOCUMENT_IMMUTABLE');

        $this->assertDatabaseHas('operation_logs', ['action' => 'legal_document.publish']);
        $this->assertDatabaseHas('operation_logs', ['action' => 'legal_document.update_draft']);
    }

    private function wechatLogin(string $openid)
    {
        return $this->postJson('/api/v1/auth/wechat-mini/login', [
            'code' => "local:{$openid}:union-{$openid}",
        ], $this->headers())->assertOk();
    }

    private function acceptAllPending(string $token): void
    {
        $pending = $this->withToken($token)
            ->getJson('/api/v1/me/legal-consents/pending?scope=account')
            ->json('data');
        foreach ($pending as $index => $document) {
            $this->recordConsent($token, $document['id'], 'accept', 'accept-all-'.$index.'-0001')
                ->assertCreated();
        }
    }

    private function recordConsent(
        string $token,
        string $documentId,
        string $action,
        string $idempotencyKey,
        bool $acknowledge = false,
    ) {
        return $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey, ...$this->headers()])
            ->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $documentId,
                'action' => $action,
                'acknowledge_service_restriction' => $acknowledge,
            ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Client-Platform' => 'wechat-mini', 'X-Device-Id' => 'legal-test-device'];
    }
}
