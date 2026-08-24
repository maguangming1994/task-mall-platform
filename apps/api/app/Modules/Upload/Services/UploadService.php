<?php

namespace App\Modules\Upload\Services;

use App\Models\User;
use App\Modules\Upload\Exceptions\UploadException;
use App\Modules\Upload\Jobs\ScanUploadedFile;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class UploadService
{
    public function __construct(
        private readonly UploadPolicy $policy,
        private readonly ControlledUploadDiskResolver $disks,
    ) {}

    /** @return array{file:UploadedFile,ticket:array<string,mixed>} */
    public function createTicket(User $user, array $data, string $idempotencyKey): array
    {
        $hash = $this->fingerprint($idempotencyKey);
        $existing = UploadedFile::query()->where('owner_user_id', $user->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            $existingToken = $existing->upload_token_ciphertext === null ? null : Crypt::decryptString($existing->upload_token_ciphertext);

            return ['file' => $existing, 'ticket' => $this->ticketFor($existing, $existingToken)];
        }
        $validated = $this->policy->validate($data['filename'], $data['content_type'], $data['size'], $data['purpose']);
        $token = Str::random(64);
        $file = UploadedFile::query()->create([
            'owner_user_id' => $user->id,
            'purpose' => $data['purpose'],
            'original_name' => basename(str_replace('\\', '/', trim($data['filename']))),
            'extension' => $validated['extension'],
            'declared_mime' => strtolower($data['content_type']),
            'declared_size' => $data['size'],
            'disk' => $this->disks->activeDiskName(),
            'quarantine_path' => 'quarantine/'.now()->format('Y/m/d').'/'.Str::uuid().'.'.$validated['extension'],
            'status' => 'issued',
            'upload_token_hash' => $this->fingerprint($token),
            'upload_token_ciphertext' => Crypt::encryptString($token),
            'idempotency_key_hash' => $hash,
            'ticket_expires_at' => now()->addSeconds(config('uploads.ticket_ttl_seconds')),
        ]);

        return ['file' => $file, 'ticket' => $this->ticketFor($file, $token)];
    }

    public function proxyUpload(Request $request, UploadedFile $file): UploadedFile
    {
        if (config('uploads.mode') !== 'local_proxy' && $file->disk !== 'local') {
            throw new UploadException('UPLOAD_PROXY_DISABLED', '当前环境不开放代理上传。', 404);
        }
        $token = (string) $request->header('X-Upload-Token');
        if ($file->status !== 'issued' || $file->ticket_expires_at->isPast() || ! hash_equals((string) $file->upload_token_hash, $this->fingerprint($token))) {
            throw new UploadException('UPLOAD_TICKET_INVALID', '上传票据无效或已过期。', 403);
        }
        if (strtolower((string) $request->header('Content-Type')) !== $file->declared_mime) {
            throw new UploadException('UPLOAD_CONTENT_TYPE_MISMATCH', '实际 Content-Type 与票据不一致。', 422);
        }
        $content = $request->getContent();
        if (strlen($content) !== $file->declared_size) {
            throw new UploadException('UPLOAD_SIZE_MISMATCH', '实际文件大小与票据不一致。', 422);
        }
        if (! $this->disks->disk($file->disk)->put($file->quarantine_path, $content, ['visibility' => 'private'])) {
            throw new UploadException('UPLOAD_STORAGE_FAILED', '文件写入隔离区失败。', 503);
        }
        $file->update(['status' => 'uploaded', 'actual_size' => strlen($content), 'uploaded_at' => now(), 'upload_token_hash' => null, 'upload_token_ciphertext' => null]);

        return $file->fresh();
    }

    public function complete(User $user, UploadedFile $file): UploadedFile
    {
        $this->authorizeOwner($user, $file);
        if ($file->status === 'clean' || $file->status === 'rejected') {
            return $file;
        }
        if ($file->ticket_expires_at->isPast() && $file->status === 'issued') {
            $file->update(['status' => 'expired', 'upload_token_hash' => null, 'upload_token_ciphertext' => null]);
            throw new UploadException('UPLOAD_TICKET_EXPIRED', '上传票据已过期。', 410);
        }
        $disk = $this->disks->disk($file->disk);
        if (! $disk->exists($file->quarantine_path)) {
            throw new UploadException('UPLOAD_OBJECT_MISSING', '隔离区中尚未发现上传对象。', 409);
        }
        $actualSize = $disk->size($file->quarantine_path);
        if ($actualSize !== $file->declared_size) {
            $file->update(['status' => 'rejected', 'actual_size' => $actualSize, 'rejection_code' => 'size_mismatch']);
            throw new UploadException('UPLOAD_SIZE_MISMATCH', '对象大小与票据不一致。', 422);
        }
        $file->update(['status' => 'scanning', 'actual_size' => $actualSize, 'uploaded_at' => $file->uploaded_at ?? now()]);
        ScanUploadedFile::dispatch($file->id);

        return $file->fresh();
    }

    public function authorizeOwner(User $user, UploadedFile $file): void
    {
        if ($file->owner_user_id !== $user->id) {
            throw new UploadException('FILE_ACCESS_DENIED', '无权访问该私有文件。', 403);
        }
    }

    /** @return array<string,mixed> */
    private function ticketFor(UploadedFile $file, ?string $token): array
    {
        if (config('uploads.mode') === 's3_presigned' && $file->disk !== 'local') {
            $signed = $this->disks->disk($file->disk)->temporaryUploadUrl($file->quarantine_path, $file->ticket_expires_at, [
                'ContentType' => $file->declared_mime,
                'ACL' => 'private',
            ]);

            return ['file_id' => $file->public_id, 'upload_url' => $signed['url'], 'method' => 'PUT', 'headers' => $signed['headers'], 'expires_at' => $file->ticket_expires_at->toIso8601String(), 'status' => $file->status];
        }
        if ($token === null && $file->status === 'issued') {
            throw new UploadException('UPLOAD_TICKET_REPLAYED', '上传票据明文只在首次创建时返回。', 409);
        }

        return ['file_id' => $file->public_id, 'upload_url' => rtrim(config('app.url'), '/').'/api/v1/files/'.$file->public_id.'/content', 'method' => 'PUT', 'headers' => ['Content-Type' => $file->declared_mime, 'X-Upload-Token' => $token], 'expires_at' => $file->ticket_expires_at->toIso8601String(), 'status' => $file->status];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('auth_tokens.identity_hash_secret')));
    }
}
