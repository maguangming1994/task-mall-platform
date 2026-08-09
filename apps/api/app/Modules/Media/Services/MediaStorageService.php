<?php

namespace App\Modules\Media\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Media\Jobs\MigrateMediaStorage;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaStorageMigration;
use App\Modules\Media\Models\MediaStorageProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MediaStorageService
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @return array<int,array<string,mixed>> */
    public function providers(): array
    {
        return MediaStorageProvider::query()->orderBy('id')->get()->map(fn (MediaStorageProvider $provider) => $this->presentProvider($provider))->all();
    }

    /** @param array<string,mixed> $data */
    public function configure(MediaStorageProvider $provider, array $data): MediaStorageProvider
    {
        if ($provider->provider === 'local') {
            if (! ($data['enabled'] ?? true) && $provider->active) {
                throw ValidationException::withMessages(['enabled' => '当前正在使用的本地存储不能停用，请先切换到其他存储。']);
            }
            $data = [
                'enabled' => (bool) ($data['enabled'] ?? true),
                'public_base_url' => $data['public_base_url'] ?? rtrim((string) config('app.url'), '/').'/storage',
            ];
        } elseif (! ($data['enabled'] ?? false) && $provider->active) {
            throw ValidationException::withMessages(['enabled' => '当前正在使用的存储不能直接停用，请先切换到其他存储。']);
        }

        if (blank($data['access_key'] ?? null)) {
            unset($data['access_key']);
        }
        if (blank($data['secret_key'] ?? null)) {
            unset($data['secret_key']);
        }
        $provider->update($data);

        return $provider->fresh();
    }

    public function test(MediaStorageProvider $provider): array
    {
        if (! $provider->enabled) {
            throw ValidationException::withMessages(['enabled' => '请先启用并保存该存储配置。']);
        }
        $path = 'health-check/'.Str::uuid().'.txt';
        try {
            $disk = $this->disk($provider);
            if (! $disk->put($path, 'task-mall-storage-check-'.now()->toIso8601String(), ['ContentType' => 'text/plain'])) {
                throw new RuntimeException('测试对象写入失败。');
            }
            if (! $disk->exists($path)) {
                throw new RuntimeException('测试对象写入后无法读取。');
            }
            $disk->delete($path);
            $provider->update(['last_tested_at' => now(), 'last_test_status' => 'success', 'last_test_message' => '连接、写入、读取和删除均正常。']);

            return ['ok' => true, 'message' => '连接、写入、读取和删除均正常。'];
        } catch (\Throwable $exception) {
            report($exception);
            $message = Str::limit($exception->getMessage(), 450);
            $provider->update(['last_tested_at' => now(), 'last_test_status' => 'failed', 'last_test_message' => $message]);
            throw ValidationException::withMessages(['provider' => '存储连通性测试失败：'.$message]);
        }
    }

    public function activate(MediaStorageProvider $provider): MediaStorageProvider
    {
        $this->test($provider);
        DB::transaction(function () use ($provider): void {
            MediaStorageProvider::query()->where('active', true)->update(['active' => false, 'updated_at' => now()]);
            $provider->update(['active' => true, 'enabled' => true]);
        });

        return $provider->fresh();
    }

    public function upload(UploadedFile $file, AdminUser $admin): MediaAsset
    {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw ValidationException::withMessages(['file' => '无法读取上传文件。']);
        }

        return $this->storeBytes($bytes, $file->getClientOriginalName(), 'local_upload', $admin, $file->getClientMimeType());
    }

    public function importUrl(string $url, AdminUser $admin): MediaAsset
    {
        $this->assertPublicHttpsUrl($url);
        $response = Http::connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['url' => "图片下载失败（HTTP {$response->status()}），请使用可直接访问的图片地址。"]);
        }
        $contentLength = (int) $response->header('Content-Length');
        if ($contentLength > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages(['url' => '远程图片不能超过 10MB。']);
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'remote-image';

        return $this->storeBytes($response->body(), $name, 'url_import', $admin, $response->header('Content-Type'));
    }

    /** @return array<string,mixed> */
    public function presentAsset(MediaAsset $asset): array
    {
        return ['id' => $asset->public_id, 'url' => $asset->public_url, 'name' => $asset->original_name, 'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes, 'provider' => $asset->storage_provider, 'status' => $asset->status];
    }

    public function startMigration(string $targetProvider, bool $deleteSource, AdminUser $admin): MediaStorageMigration
    {
        $source = $this->activeProvider();
        $target = MediaStorageProvider::query()->where('provider', $targetProvider)->where('enabled', true)->firstOrFail();
        if ($source->provider === $target->provider) {
            throw ValidationException::withMessages(['target_provider' => '迁移目标不能与当前存储相同。']);
        }
        $this->test($target);
        $running = MediaStorageMigration::query()->whereIn('status', ['pending', 'running'])->exists();
        if ($running) {
            throw ValidationException::withMessages(['migration' => '已有存储迁移任务正在执行。']);
        }
        $migration = DB::transaction(function () use ($source, $target, $deleteSource, $admin): MediaStorageMigration {
            MediaStorageProvider::query()->where('active', true)->update(['active' => false, 'updated_at' => now()]);
            $target->update(['active' => true, 'enabled' => true]);

            return MediaStorageMigration::query()->create([
                'from_provider' => $source->provider,
                'to_provider' => $target->provider,
                'status' => 'pending',
                'total_assets' => MediaAsset::query()->where('storage_provider', $source->provider)->where('status', 'active')->count(),
                'delete_source' => $deleteSource,
                'requested_by_admin_id' => $admin->id,
            ]);
        });
        MigrateMediaStorage::dispatch($migration->id);

        return $migration;
    }

    /** @return array<int,array<string,mixed>> */
    public function migrations(): array
    {
        return MediaStorageMigration::query()->latest('id')->limit(20)->get()->map(fn (MediaStorageMigration $item) => $this->presentMigration($item))->all();
    }

    /** @return array<string,mixed> */
    public function presentMigration(MediaStorageMigration $item): array
    {
        return ['id' => $item->public_id, 'from_provider' => $item->from_provider, 'to_provider' => $item->to_provider, 'status' => $item->status, 'total_assets' => $item->total_assets, 'processed_assets' => $item->processed_assets, 'failed_assets' => $item->failed_assets, 'delete_source' => $item->delete_source, 'errors' => $item->error_summary ?? [], 'created_at' => $item->created_at?->toIso8601String(), 'completed_at' => $item->completed_at?->toIso8601String()];
    }

    public function disk(MediaStorageProvider $provider): FilesystemAdapter
    {
        if ($provider->provider === 'local') {
            return Storage::build(['driver' => 'local', 'root' => storage_path('app/public'), 'url' => $provider->public_base_url, 'visibility' => 'public', 'throw' => true]);
        }
        foreach (['endpoint', 'region', 'bucket', 'public_base_url', 'access_key', 'secret_key'] as $required) {
            if (blank($provider->{$required})) {
                throw ValidationException::withMessages([$required => "{$provider->display_name} 缺少 {$required} 配置。"]);
            }
        }

        return Storage::build([
            'driver' => 's3', 'key' => $provider->access_key, 'secret' => $provider->secret_key,
            'region' => $provider->region, 'bucket' => $provider->bucket, 'endpoint' => $provider->endpoint,
            'url' => rtrim($provider->public_base_url, '/'), 'use_path_style_endpoint' => false,
            'throw' => true,
        ]);
    }

    public function activeProvider(): MediaStorageProvider
    {
        return MediaStorageProvider::query()->where('active', true)->where('enabled', true)->firstOrFail();
    }

    public function publicUrl(MediaStorageProvider $provider, string $path): string
    {
        return rtrim((string) $provider->public_base_url, '/').'/'.ltrim($path, '/');
    }

    /** @return array<string,mixed> */
    private function presentProvider(MediaStorageProvider $provider): array
    {
        return [
            'provider' => $provider->provider, 'display_name' => $provider->display_name, 'enabled' => $provider->enabled, 'active' => $provider->active,
            'endpoint' => $provider->endpoint, 'region' => $provider->region, 'bucket' => $provider->bucket, 'public_base_url' => $provider->public_base_url,
            'access_key_configured' => filled($provider->access_key), 'access_key_masked' => $this->mask($provider->access_key), 'secret_key_configured' => filled($provider->secret_key),
            'last_tested_at' => $provider->last_tested_at?->toIso8601String(), 'last_test_status' => $provider->last_test_status, 'last_test_message' => $provider->last_test_message,
        ];
    }

    private function storeBytes(string $bytes, string $originalName, string $sourceType, AdminUser $admin, ?string $declaredMime): MediaAsset
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages(['file' => '图片不能为空且不能超过 10MB。']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! isset(self::MIME_EXTENSIONS[$mime]) || getimagesizefromstring($bytes) === false) {
            throw ValidationException::withMessages(['file' => '仅支持真实的 JPG、PNG、WebP 图片。']);
        }
        if ($declaredMime && ! str_starts_with(strtolower($declaredMime), $mime)) {
            throw ValidationException::withMessages(['file' => '图片声明类型与实际内容不一致。']);
        }
        $provider = $this->activeProvider();
        $path = 'media/'.now()->format('Y/m').'/'.Str::uuid().'.'.self::MIME_EXTENSIONS[$mime];
        $disk = $this->disk($provider);
        if (! $disk->put($path, $bytes, ['ContentType' => $mime])) {
            throw new RuntimeException('图片写入对象存储失败。');
        }
        [$width, $height] = getimagesizefromstring($bytes);

        return MediaAsset::query()->create([
            'source_type' => $sourceType, 'original_name' => Str::limit(basename(str_replace('\\', '/', $originalName)), 255, ''),
            'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'storage_provider' => $provider->provider, 'object_path' => $path, 'public_url' => $this->publicUrl($provider, $path),
            'status' => 'active', 'metadata' => ['width' => $width, 'height' => $height], 'created_by_admin_id' => $admin->id,
        ]);
    }

    private function assertPublicHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => '只允许不含账号信息的 HTTPS 图片地址。']);
        }
        $host = (string) $parts['host'];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        if ($records === []) {
            throw ValidationException::withMessages(['url' => '图片域名无法解析。']);
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! $ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages(['url' => '图片地址不能指向本机、内网或保留网络。']);
            }
        }
    }

    private function mask(?string $value): ?string
    {
        return filled($value) ? str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4) : null;
    }
}
