<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaStorageMigration;
use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Media\Services\MediaStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class MigrateMediaStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $migrationId)
    {
        $this->onQueue('media-migrations');
    }

    public function handle(MediaStorageService $storage): void
    {
        $migration = MediaStorageMigration::query()->find($this->migrationId);
        if ($migration === null || $migration->status !== 'pending') {
            return;
        }

        $source = MediaStorageProvider::query()->where('provider', $migration->from_provider)->firstOrFail();
        $target = MediaStorageProvider::query()->where('provider', $migration->to_provider)->firstOrFail();
        $sourceDisk = $storage->disk($source);
        $targetDisk = $storage->disk($target);
        $errors = [];
        $migration->update(['status' => 'running', 'started_at' => now()]);

        MediaAsset::query()
            ->where('storage_provider', $source->provider)
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(100, function ($assets) use ($migration, $sourceDisk, $targetDisk, $target, $storage, &$errors): void {
                foreach ($assets as $asset) {
                    try {
                        $stream = $sourceDisk->readStream($asset->object_path);
                        if (! is_resource($stream)) {
                            throw new RuntimeException('无法读取源文件。');
                        }
                        try {
                            $targetDisk->writeStream($asset->object_path, $stream, [
                                'ContentType' => $asset->mime_type,
                            ]);
                        } finally {
                            fclose($stream);
                        }
                        if (! $targetDisk->exists($asset->object_path) || $targetDisk->size($asset->object_path) !== $asset->size_bytes) {
                            $targetDisk->delete($asset->object_path);
                            throw new RuntimeException('目标文件校验失败。');
                        }

                        $asset->update([
                            'storage_provider' => $target->provider,
                            'public_url' => $storage->publicUrl($target, $asset->object_path),
                        ]);
                        if ($migration->delete_source) {
                            $sourceDisk->delete($asset->object_path);
                        }
                        $migration->increment('processed_assets');
                    } catch (Throwable $exception) {
                        report($exception);
                        $migration->increment('failed_assets');
                        if (count($errors) < 50) {
                            $errors[] = ['asset_id' => $asset->public_id, 'message' => mb_substr($exception->getMessage(), 0, 300)];
                        }
                    }
                }
            });

        $migration->refresh();
        $migration->update([
            'status' => $migration->failed_assets > 0 ? 'completed_with_errors' : 'completed',
            'error_summary' => $errors,
            'completed_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        MediaStorageMigration::query()->whereKey($this->migrationId)->update([
            'status' => 'failed',
            'error_summary' => [['message' => mb_substr($exception->getMessage(), 0, 300)]],
            'completed_at' => now(),
        ]);
    }
}
