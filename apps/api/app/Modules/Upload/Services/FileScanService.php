<?php

namespace App\Modules\Upload\Services;

use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FileScanService
{
    public function scan(UploadedFile $file): void
    {
        if (config('uploads.scanner') === 'webhook') {
            $this->scanWithWebhook($file);

            return;
        }
        if (config('uploads.scanner') !== 'local_safe') {
            $this->reject($file, 'external_scanner_required');

            return;
        }
        $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot open quarantined object.');
        }
        $hash = hash_init('sha256');
        $firstBytes = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new RuntimeException('Cannot read quarantined object.');
            }
            if (strlen($firstBytes) < 16384) {
                $firstBytes .= substr($chunk, 0, 16384 - strlen($firstBytes));
            }
            hash_update($hash, $chunk);
        }
        fclose($stream);

        if (str_starts_with($firstBytes, 'MZ') || str_starts_with($firstBytes, "\x7fELF") || stripos($firstBytes, '<?php') !== false) {
            $this->reject($file, 'malicious_signature');

            return;
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($firstBytes) ?: 'application/octet-stream';
        $category = collect(config('uploads.extensions'))->search(fn ($extensions) => in_array($file->extension, $extensions, true));
        $allowedDetected = $category === null ? [] : config("uploads.mimes.{$category}", []);
        if (! in_array($detected, $allowedDetected, true)) {
            $this->reject($file, 'mime_mismatch', $detected);

            return;
        }
        $file->update([
            'status' => 'clean',
            'detected_mime' => $detected,
            'sha256' => hash_final($hash),
            'scanned_at' => now(),
            'rejection_code' => null,
        ]);
    }

    private function reject(UploadedFile $file, string $code, ?string $detectedMime = null): void
    {
        $file->update(['status' => 'rejected', 'rejection_code' => $code, 'detected_mime' => $detectedMime, 'scanned_at' => now()]);
    }

    private function scanWithWebhook(UploadedFile $file): void
    {
        $endpoint = (string) config('uploads.scanner_webhook.endpoint');
        $token = (string) config('uploads.scanner_webhook.token');
        if ($endpoint === '' || $token === '') {
            $this->reject($file, 'external_scanner_unconfigured');

            return;
        }
        try {
            $downloadUrl = Storage::disk($file->disk)->temporaryUrl($file->quarantine_path, now()->addMinutes(10));
        } catch (Throwable) {
            $downloadUrl = null;
        }
        try {
            $response = Http::asJson()->withToken($token)
                ->timeout((int) config('uploads.scanner_webhook.timeout_seconds'))
                ->post($endpoint, [
                    'file_id' => $file->public_id,
                    'download_url' => $downloadUrl,
                    'disk' => $file->disk,
                    'object_key' => $file->quarantine_path,
                    'filename' => $file->original_name,
                    'declared_mime' => $file->declared_mime,
                    'size' => $file->actual_size ?? $file->declared_size,
                ]);
        } catch (Throwable) {
            $this->reject($file, 'external_scan_unavailable');

            return;
        }
        if (! $response->successful() || ! is_bool($response->json('clean'))) {
            $this->reject($file, 'external_scan_invalid_response');

            return;
        }
        $detected = (string) $response->json('detected_mime', 'application/octet-stream');
        if (! $response->json('clean')) {
            $this->reject($file, (string) $response->json('reason_code', 'external_malware'), $detected);

            return;
        }
        $category = collect(config('uploads.extensions'))->search(fn ($extensions) => in_array($file->extension, $extensions, true));
        if ($category === null || ! in_array($detected, config("uploads.mimes.{$category}", []), true)) {
            $this->reject($file, 'mime_mismatch', $detected);

            return;
        }
        $file->update([
            'status' => 'clean',
            'detected_mime' => $detected,
            'sha256' => $response->json('sha256'),
            'scanned_at' => now(),
            'rejection_code' => null,
        ]);
    }
}
