<?php

namespace App\Modules\Upload\Jobs;

use App\Modules\Upload\Models\UploadedFile;
use App\Modules\Upload\Services\FileScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ScanUploadedFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $uploadedFileId)
    {
        $this->onQueue('file-scans');
    }

    public function handle(FileScanService $scanner): void
    {
        $file = UploadedFile::query()->find($this->uploadedFileId);
        if ($file === null || $file->status !== 'scanning') {
            return;
        }
        try {
            $scanner->scan($file);
        } catch (Throwable $exception) {
            $file->update(['status' => 'rejected', 'rejection_code' => 'scan_failed', 'scanned_at' => now()]);
            throw $exception;
        }
    }
}
