<?php

namespace Tests\Feature;

use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ControlledUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['uploads.disk' => 's3', 'uploads.mode' => 'local_proxy', 'uploads.scanner' => 'local_safe']);
    }

    public function test_private_image_upload_is_idempotent_quarantined_scanned_and_owner_downloadable(): void
    {
        $token = $this->phoneLogin('13800138021');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $headers = ['Idempotency-Key' => 'upload-image-ticket-0001'];
        $payload = ['filename' => 'evidence.png', 'content_type' => 'image/png', 'size' => strlen($png), 'purpose' => 'task_submission'];

        $first = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/files/upload-tickets', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'issued');
        $second = $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/files/upload-tickets', $payload)
            ->assertCreated();
        $this->assertSame($first->json('data.file_id'), $second->json('data.file_id'));
        $this->assertSame($first->json('data.headers.X-Upload-Token'), $second->json('data.headers.X-Upload-Token'));

        $fileId = $first->json('data.file_id');
        $this->call('PUT', "/api/v1/files/{$fileId}/content", [], [], [], [
            'CONTENT_TYPE' => 'image/png',
            'HTTP_X_UPLOAD_TOKEN' => $first->json('data.headers.X-Upload-Token'),
        ], $png)
            ->assertOk()->assertJsonPath('data.status', 'uploaded');

        $this->withToken($token)->getJson("/api/v1/files/{$fileId}/content")
            ->assertConflict()->assertJsonPath('code', 'FILE_NOT_AVAILABLE');
        $this->withToken($token)->postJson("/api/v1/files/{$fileId}/complete")
            ->assertOk()->assertJsonPath('data.status', 'clean')->assertJsonPath('data.detected_mime', 'image/png');
        $this->withToken($token)->get("/api/v1/files/{$fileId}/content")->assertOk();

        $file = UploadedFile::query()->firstOrFail();
        Storage::disk('s3')->assertExists($file->quarantine_path);
        $this->assertSame(64, strlen((string) $file->sha256));
        $this->assertNull($file->upload_token_hash);
        $this->assertNull($file->upload_token_ciphertext);
    }

    public function test_private_file_cannot_be_read_by_another_user(): void
    {
        $ownerToken = $this->phoneLogin('13800138022');
        $otherToken = $this->phoneLogin('13800138023');
        $text = 'safe evidence file';
        $ticket = $this->withToken($ownerToken)->withHeaders(['Idempotency-Key' => 'upload-private-ticket-001'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'proof.txt', 'content_type' => 'text/plain', 'size' => strlen($text), 'purpose' => 'refund_evidence',
        ])->assertCreated();
        $fileId = $ticket->json('data.file_id');
        $this->call('PUT', "/api/v1/files/{$fileId}/content", [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_X_UPLOAD_TOKEN' => $ticket->json('data.headers.X-Upload-Token'),
        ], $text)->assertOk();
        $this->withToken($ownerToken)->postJson("/api/v1/files/{$fileId}/complete")->assertOk();

        $this->withToken($otherToken)->getJson("/api/v1/files/{$fileId}")
            ->assertForbidden()->assertJsonPath('code', 'FILE_ACCESS_DENIED');
        $this->withToken($otherToken)->getJson("/api/v1/files/{$fileId}/content")
            ->assertForbidden()->assertJsonPath('code', 'FILE_ACCESS_DENIED');
    }

    public function test_extension_mime_size_ticket_and_malicious_content_are_fail_closed(): void
    {
        $token = $this->phoneLogin('13800138024');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'upload-invalid-type-001'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'payload.php', 'content_type' => 'text/plain', 'size' => 20, 'purpose' => 'task_submission',
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => ''])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'proof.txt', 'content_type' => 'text/plain', 'size' => 20, 'purpose' => 'task_submission',
        ])->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

        $malicious = "<?php echo 'unsafe';";
        $ticket = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'upload-malicious-file-01'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'notes.txt', 'content_type' => 'text/plain', 'size' => strlen($malicious), 'purpose' => 'task_report',
        ])->assertCreated();
        $fileId = $ticket->json('data.file_id');
        $this->call('PUT', "/api/v1/files/{$fileId}/content", [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_X_UPLOAD_TOKEN' => $ticket->json('data.headers.X-Upload-Token'),
        ], $malicious)->assertOk();
        $this->withToken($token)->postJson("/api/v1/files/{$fileId}/complete")
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_code', 'malicious_signature');
        $this->withToken($token)->getJson("/api/v1/files/{$fileId}/content")
            ->assertConflict()->assertJsonPath('code', 'FILE_NOT_AVAILABLE');
    }

    private function phoneLogin(string $phone): string
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'upload-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();

        return $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)
            ->assertOk()->json('data.access_token');
    }
}
