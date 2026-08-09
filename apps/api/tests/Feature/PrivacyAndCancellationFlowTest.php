<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Privacy\Models\DataDeletionManifest;
use App\Modules\Privacy\Models\UserAccountConstraint;
use App\Modules\Privacy\Services\AccountCancellationService;
use App\Modules\Privacy\Services\DataDeletionService;
use App\Modules\Upload\Models\UploadedFile;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyAndCancellationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['uploads.disk' => 's3']);
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);
    }

    public function test_copy_request_is_idempotent_and_generates_a_private_expiring_export(): void
    {
        [$user, $token] = $this->loginAndConsent('13800138031');
        $headers = ['Idempotency-Key' => 'privacy-copy-request-0001'];
        $payload = ['request_type' => 'copy', 'scope' => ['profile', 'identities', 'devices']];
        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/me/privacy-requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'completed');
        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/me/privacy-requests', $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $fileId = $first->json('data.result_file_id');
        $this->withToken($token)->get("/api/v1/files/{$fileId}/content")->assertOk();
        $this->travel(8)->days();
        $freshToken = $this->phoneLogin('13800138031');
        $this->withToken($freshToken)->get("/api/v1/files/{$fileId}/content")->assertGone()->assertJsonPath('code', 'FILE_EXPIRED');
        $this->assertDatabaseCount('privacy_requests', 1);
        $this->assertDatabaseHas('uploaded_files', ['owner_user_id' => $user->id, 'purpose' => 'privacy_export', 'status' => 'clean']);
    }

    public function test_authorized_admin_can_resolve_a_privacy_request_with_recent_verification_and_audit(): void
    {
        [, $token] = $this->loginAndConsent('13800138034');
        $requestId = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'privacy-correction-request-01'])->postJson('/api/v1/me/privacy-requests', [
            'request_type' => 'correction', 'scope' => ['profile'], 'description' => '昵称资料需要更正。',
        ])->assertCreated()->json('data.id');

        $this->seed(RbacSeeder::class);
        $admin = AdminUser::query()->create(['account' => 'privacy-root', 'display_name' => 'Privacy Root', 'password' => 'AdminPassword12345!']);
        $admin->roles()->attach(Role::query()->where('code', 'super_admin')->value('id'));
        $adminToken = $this->postJson('/api/v1/admin/auth/login', ['account' => 'privacy-root', 'password' => 'AdminPassword12345!'])->assertOk()->json('data.access_token');

        $this->withToken($adminToken)->getJson('/api/v1/admin/privacy-requests?status=submitted')
            ->assertOk()->assertJsonPath('data.items.0.id', $requestId);
        $this->withToken($adminToken)->postJson("/api/v1/admin/privacy-requests/{$requestId}/resolve", [
            'decision' => 'completed', 'resolution_note' => '身份已复核，资料更正已线下完成。', 'confirm_identity_verified' => true,
        ])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertDatabaseHas('operation_logs', ['action' => 'privacy_request.resolve', 'subject_id' => $requestId]);
    }

    public function test_cancellation_eligibility_blockers_are_explicit_and_login_withdraws_cooling_off(): void
    {
        [$user, $token] = $this->loginAndConsent('13800138032');
        UserAccountConstraint::query()->create(['user_id' => $user->id, 'blocker_code' => 'ongoing_order', 'source_type' => 'order', 'source_id' => 'ORDER-1']);
        $this->withToken($token)->getJson('/api/v1/me/account-cancellation/eligibility')->assertOk()->assertJsonPath('data.eligible', false)->assertJsonPath('data.blockers.0', 'ongoing_order');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'cancel-blocked-request-01'])->postJson('/api/v1/me/account-cancellation', [
            'forfeit_points_and_coupons' => true, 'confirmation' => 'CONFIRM_ACCOUNT_CANCELLATION',
        ])->assertConflict()->assertJsonPath('code', 'ACCOUNT_CANCELLATION_BLOCKED');
        UserAccountConstraint::query()->update(['resolved_at' => now()]);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'cancel-cooling-request-01'])->postJson('/api/v1/me/account-cancellation', [
            'forfeit_points_and_coupons' => true, 'confirmation' => 'CONFIRM_ACCOUNT_CANCELLATION',
        ])->assertCreated()->assertJsonPath('data.status', 'cooling_off');

        $newToken = $this->phoneLogin('13800138032');
        $this->assertNotEmpty($newToken);
        $this->assertDatabaseHas('account_cancellation_requests', ['user_id' => $user->id, 'status' => 'cancelled', 'cancel_reason' => 'login_during_cooling_off']);
    }

    public function test_due_cancellation_rechecks_executes_anonymization_and_deletion_manifest_replays(): void
    {
        [$user, $token] = $this->loginAndConsent('13800138033');
        $contents = 'private task evidence';
        Storage::disk('s3')->put('quarantine/test/evidence.txt', $contents);
        UploadedFile::query()->create([
            'owner_user_id' => $user->id, 'purpose' => 'task_submission', 'original_name' => 'evidence.txt', 'extension' => 'txt',
            'declared_mime' => 'text/plain', 'detected_mime' => 'text/plain', 'declared_size' => strlen($contents), 'actual_size' => strlen($contents),
            'sha256' => hash('sha256', $contents), 'disk' => 's3', 'quarantine_path' => 'quarantine/test/evidence.txt', 'status' => 'clean',
            'idempotency_key_hash' => hash('sha256', 'test-file'), 'ticket_expires_at' => now()->addHour(), 'uploaded_at' => now(), 'scanned_at' => now(),
        ]);
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'cancel-execute-request-01'])->postJson('/api/v1/me/account-cancellation', [
            'forfeit_points_and_coupons' => true, 'confirmation' => 'CONFIRM_ACCOUNT_CANCELLATION',
        ])->assertCreated();
        $this->travel(8)->days();
        $this->assertSame(1, app(AccountCancellationService::class)->processDue());
        $user->refresh();
        $this->assertSame('cancelled', $user->status);
        $this->assertNull($user->phone_hash);
        $this->assertSame('已注销用户', $user->nickname);
        Storage::disk('s3')->assertMissing('quarantine/test/evidence.txt');
        $this->assertDatabaseHas('data_deletion_jobs', ['user_id' => $user->id, 'status' => 'completed']);
        $this->assertDatabaseHas('data_deletion_manifests', ['user_id' => $user->id]);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        $user->update(['status' => 'active', 'nickname' => '错误恢复昵称', 'phone' => '13800138033', 'phone_hash' => hash('sha256', 'restored')]);
        UserIdentity::query()->create(['user_id' => $user->id, 'provider' => 'restored', 'provider_user_id' => 'restored-hash', 'metadata' => []]);
        $this->assertSame(1, app(DataDeletionService::class)->replayManifests());
        $this->assertSame('cancelled', $user->fresh()->status);
        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id, 'provider' => 'restored']);
        $this->assertNotNull(DataDeletionManifest::query()->where('user_id', $user->id)->value('last_replayed_at'));
    }

    /** @return array{User,string} */
    private function loginAndConsent(string $phone): array
    {
        $token = $this->phoneLogin($phone);
        $pending = $this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope=account')->json('data');
        foreach ($pending as $index => $document) {
            $this->withToken($token)->withHeaders(['Idempotency-Key' => 'privacy-consent-'.$phone.'-'.$index, 'X-Client-Platform' => 'h5-browser'])->postJson('/api/v1/me/legal-consents', [
                'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
            ])->assertCreated();
        }
        $user = User::query()->whereNotNull('phone_hash')->latest('id')->firstOrFail();

        return [$user, $token];
    }

    private function phoneLogin(string $phone): string
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'privacy-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();

        return $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk()->json('data.access_token');
    }
}
