<?php

namespace Tests\Feature;

use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Upload\Models\UploadedFile;
use App\Modules\Upload\Services\ControlledUploadDiskResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ControlledUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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
        $this->assertSame('s3', $file->disk);
        Storage::disk($file->disk)->assertExists($file->quarantine_path);
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

    public function test_new_private_upload_binds_active_qiniu_storage_and_stays_private_until_scanned(): void
    {
        Storage::fake('qiniu_kodo');
        $this->activateQiniu();

        $token = $this->phoneLogin('13800138025');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $ticket = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'qiniu-private-ticket-001'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'payment-proof.png', 'content_type' => 'image/png', 'size' => strlen($png), 'purpose' => 'offline_recharge',
        ])->assertCreated();

        $this->assertStringNotContainsString('public-images.example.test', $ticket->json('data.upload_url'));
        $file = UploadedFile::query()->firstOrFail();
        $this->assertSame('qiniu_kodo', $file->disk);

        $this->call('PUT', "/api/v1/files/{$file->public_id}/content", [], [], [], [
            'CONTENT_TYPE' => 'image/png', 'HTTP_X_UPLOAD_TOKEN' => $ticket->json('data.headers.X-Upload-Token'),
        ], $png)->assertOk();
        $this->withToken($token)->getJson("/api/v1/files/{$file->public_id}/content")
            ->assertConflict()->assertJsonPath('code', 'FILE_NOT_AVAILABLE');
        $this->withToken($token)->postJson("/api/v1/files/{$file->public_id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'clean');
        Storage::disk('qiniu_kodo')->assertExists($file->quarantine_path);
    }

    public function test_cloud_presigned_ticket_requires_private_acl_and_never_uses_the_public_media_domain(): void
    {
        $this->activateQiniu();
        config(['uploads.mode' => 's3_presigned']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUploadUrl')->once()->withArgs(function (string $path, $expiresAt, array $options): bool {
            return str_starts_with($path, 'quarantine/')
                && $options['ContentType'] === 'image/png'
                && $options['ACL'] === 'private';
        })->andReturn([
            'url' => 'https://task-mall-private-test.s3.cn-south-1.qiniucs.com/quarantine/signed-upload',
            'headers' => ['Content-Type' => 'image/png', 'x-amz-acl' => 'private'],
        ]);
        Storage::set('qiniu_kodo', $disk);

        $token = $this->phoneLogin('13800138028');
        $response = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'qiniu-presigned-ticket-1'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'presigned.png', 'content_type' => 'image/png', 'size' => 68, 'purpose' => 'offline_recharge',
        ])->assertCreated();

        $response->assertJsonPath('data.headers.x-amz-acl', 'private');
        $this->assertStringNotContainsString('public-images.example.test', $response->json('data.upload_url'));
        $this->assertSame('qiniu_kodo', UploadedFile::query()->firstOrFail()->disk);
    }

    public function test_active_cloud_storage_without_a_private_bucket_fails_closed(): void
    {
        $this->activateQiniu(null);

        $token = $this->phoneLogin('13800138029');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'qiniu-missing-private-bucket'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'payment-proof.png', 'content_type' => 'image/png', 'size' => 68, 'purpose' => 'offline_recharge',
        ])->assertStatus(503)->assertJsonPath('code', 'UPLOAD_STORAGE_UNAVAILABLE');

        $this->assertDatabaseCount('uploaded_files', 0);
    }

    public function test_active_cloud_storage_cannot_reuse_the_public_media_bucket(): void
    {
        $this->activateQiniu('task-mall-public-test');

        $token = $this->phoneLogin('13800138030');
        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'qiniu-shared-public-bucket'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'payment-proof.png', 'content_type' => 'image/png', 'size' => 68, 'purpose' => 'offline_recharge',
        ])->assertStatus(503)->assertJsonPath('code', 'UPLOAD_STORAGE_UNAVAILABLE');

        $this->assertDatabaseCount('uploaded_files', 0);
    }

    public function test_active_local_storage_uses_the_controlled_proxy_even_in_presigned_mode(): void
    {
        config(['uploads.mode' => 's3_presigned', 'uploads.disk' => 'local']);
        $token = $this->phoneLogin('13800138027');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $ticket = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'local-private-ticket-001'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'local-proof.png', 'content_type' => 'image/png', 'size' => strlen($png), 'purpose' => 'offline_recharge',
        ])->assertCreated();

        $file = UploadedFile::query()->firstOrFail();
        $this->assertSame('local', $file->disk);
        $this->assertStringContainsString("/api/v1/files/{$file->public_id}/content", $ticket->json('data.upload_url'));
        $this->call('PUT', "/api/v1/files/{$file->public_id}/content", [], [], [], [
            'CONTENT_TYPE' => 'image/png', 'HTTP_X_UPLOAD_TOKEN' => $ticket->json('data.headers.X-Upload-Token'),
        ], $png)->assertOk();
    }

    public function test_legacy_s3_file_remains_usable_after_the_active_provider_changes(): void
    {
        MediaStorageProvider::query()->update(['active' => false]);
        $token = $this->phoneLogin('13800138026');
        $text = 'legacy private evidence';
        $ticket = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'legacy-s3-ticket-0001'])->postJson('/api/v1/files/upload-tickets', [
            'filename' => 'legacy.txt', 'content_type' => 'text/plain', 'size' => strlen($text), 'purpose' => 'task_report',
        ])->assertCreated();
        $file = UploadedFile::query()->firstOrFail();
        $this->assertSame('s3', $file->disk);

        $this->call('PUT', "/api/v1/files/{$file->public_id}/content", [], [], [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_X_UPLOAD_TOKEN' => $ticket->json('data.headers.X-Upload-Token'),
        ], $text)->assertOk();
        MediaStorageProvider::query()->where('provider', 'local')->update(['enabled' => true, 'active' => true]);

        $this->withToken($token)->postJson("/api/v1/files/{$file->public_id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'clean');
        Storage::disk('s3')->assertExists($file->quarantine_path);
    }

    private function phoneLogin(string $phone): string
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'upload-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();

        return $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)
            ->assertOk()->json('data.access_token');
    }

    private function activateQiniu(?string $privateBucket = 'task-mall-private-test'): void
    {
        MediaStorageProvider::query()->where('active', true)->update(['active' => false]);
        MediaStorageProvider::query()->where('provider', 'qiniu_kodo')->firstOrFail()->update([
            'enabled' => true,
            'active' => true,
            'endpoint' => 'https://s3.cn-south-1.qiniucs.com',
            'region' => 'cn-south-1',
            'bucket' => 'task-mall-public-test',
            'private_bucket' => $privateBucket,
            'public_base_url' => 'https://public-images.example.test',
            'access_key' => 'QINIU_TEST_ACCESS_KEY',
            'secret_key' => 'QINIU_TEST_SECRET_KEY',
        ]);
        $resolver = Mockery::mock(ControlledUploadDiskResolver::class)->makePartial();
        $resolver->shouldReceive('disk')->with('qiniu_kodo')->andReturnUsing(fn () => Storage::disk('qiniu_kodo'));
        $this->app->instance(ControlledUploadDiskResolver::class, $resolver);
    }
}
