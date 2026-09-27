<?php

namespace App\Modules\Media\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Media\Jobs\MigrateMediaStorage;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaStorageMigration;
use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Upload\Models\UploadedFile as ControlledUploadedFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MediaStorageService
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private const MAX_IMAGE_DIMENSION = 12000;

    private const MAX_IMAGE_PIXELS = 40_000_000;

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
                'public_base_url' => rtrim(trim((string) ($data['public_base_url'] ?? rtrim((string) config('app.url'), '/').'/storage')), '/'),
            ];
        } else {
            if (! ($data['enabled'] ?? false) && $provider->active) {
                throw ValidationException::withMessages(['enabled' => '当前正在使用的存储不能直接停用，请先切换到其他存储。']);
            }
            foreach (['access_key', 'secret_key'] as $credential) {
                if (blank($data[$credential] ?? null)) {
                    unset($data[$credential]);
                } else {
                    $data[$credential] = trim((string) $data[$credential]);
                }
            }
            $data = $this->normalizeCloudConfiguration($provider, $data);
            $storedObjects = $this->storedObjectState($provider);
            $this->assertStoredObjectTopologyChangeAllowed($provider, $data, $storedObjects);
            if (($provider->active || $storedObjects['public'] || $storedObjects['private']) && $this->configurationChanged($provider, $data)) {
                $candidate = $provider->replicate();
                $candidate->forceFill($data);
                try {
                    $message = $this->probe($candidate);
                } catch (Throwable $exception) {
                    report($exception);
                    throw ValidationException::withMessages(['provider' => '候选配置连通性测试失败，原配置已保留：'.Str::limit($exception->getMessage(), 350)]);
                }
                $data['last_tested_at'] = now();
                $data['last_test_status'] = 'success';
                $data['last_test_message'] = $message;
            }
        }
        $refreshPublicUrls = $this->fieldChanged($provider, $data, 'public_base_url')
            && MediaAsset::query()->where('storage_provider', $provider->provider)->exists();

        DB::transaction(function () use ($provider, $data, $refreshPublicUrls): void {
            $provider->update($data);
            if ($refreshPublicUrls) {
                $this->refreshPublicUrls($provider);
            }
        });

        return $provider->fresh();
    }

    public function test(MediaStorageProvider $provider): array
    {
        if (! $provider->enabled) {
            throw ValidationException::withMessages(['enabled' => '请先启用并保存该存储配置。']);
        }
        try {
            $message = $this->probe($provider);
            $provider->update(['last_tested_at' => now(), 'last_test_status' => 'success', 'last_test_message' => $message]);

            return ['ok' => true, 'message' => $message];
        } catch (Throwable $exception) {
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
        $target = $this->assertPublicHttpsUrl($url);
        $response = $this->requestPinnedRemoteImage($url, $target);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['url' => "图片下载失败（HTTP {$response->status()}），请使用可直接访问的图片地址。"]);
        }
        $contentLength = trim((string) $response->header('Content-Length'));
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages(['url' => '远程图片不能超过 10MB。']);
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'remote-image';

        return $this->storeBytes($this->readRemoteBody($response), $name, 'url_import', $admin, $response->header('Content-Type'));
    }

    /** @param array<string, mixed> $metadata */
    public function storeBundledImage(string $path, AdminUser $admin, array $metadata = []): MediaAsset
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['file' => '仓库内图片不存在或不可读。']);
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw ValidationException::withMessages(['file' => '无法读取仓库内图片。']);
        }

        return $this->storeBytes($bytes, basename($path), 'bundled_import', $admin, null, $metadata);
    }

    /** @return array<string,mixed> */
    public function presentAsset(MediaAsset $asset): array
    {
        return ['id' => $asset->public_id, 'url' => $asset->deliveryUrl(), 'name' => $asset->original_name, 'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes, 'provider' => $asset->storage_provider, 'status' => $asset->status];
    }

    public function startMigration(string $targetProvider, bool $deleteSource, AdminUser $admin): MediaStorageMigration
    {
        $source = $this->activeProvider();
        $target = MediaStorageProvider::query()->where('provider', $targetProvider)->where('enabled', true)->firstOrFail();
        if ($source->provider === $target->provider) {
            throw ValidationException::withMessages(['target_provider' => '迁移目标不能与当前存储相同。']);
        }
        if ($deleteSource && MediaAsset::query()->where('storage_provider', $source->provider)->where('status', 'active')->cursor()->contains(
            fn (MediaAsset $asset): bool => (int) data_get($asset->metadata, 'delivery_url_version', 0) < 1,
        )) {
            throw ValidationException::withMessages(['delete_source' => '存在使用历史原始地址的素材，本次迁移必须保留源文件。']);
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

        return $this->cloudDisk($provider, (string) $provider->bucket);
    }

    private function cloudDisk(MediaStorageProvider $provider, string $bucket): FilesystemAdapter
    {
        return Storage::build([
            'driver' => 's3', 'key' => $provider->access_key, 'secret' => $provider->secret_key,
            'region' => $provider->region, 'bucket' => $bucket, 'endpoint' => $provider->endpoint,
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
            'endpoint' => $provider->endpoint, 'region' => $provider->region, 'bucket' => $provider->bucket, 'private_bucket' => $provider->private_bucket, 'public_base_url' => $provider->public_base_url,
            'access_key_configured' => filled($provider->access_key), 'access_key_masked' => $this->mask($provider->access_key), 'secret_key_configured' => filled($provider->secret_key),
            'last_tested_at' => $provider->last_tested_at?->toIso8601String(), 'last_test_status' => $provider->last_test_status, 'last_test_message' => $provider->last_test_message,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function storeBytes(string $bytes, string $originalName, string $sourceType, AdminUser $admin, ?string $declaredMime, array $metadata = []): MediaAsset
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages(['file' => '图片不能为空且不能超过 10MB。']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $imageInfo = getimagesizefromstring($bytes);
        if (! isset(self::MIME_EXTENSIONS[$mime]) || $imageInfo === false) {
            throw ValidationException::withMessages(['file' => '仅支持真实的 JPG、PNG、WebP 图片。']);
        }
        [$width, $height] = $imageInfo;
        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw ValidationException::withMessages(['file' => '图片像素尺寸过大，请压缩后重试。']);
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

        return MediaAsset::query()->create([
            'source_type' => $sourceType, 'original_name' => Str::limit(basename(str_replace('\\', '/', $originalName)), 255, ''),
            'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'storage_provider' => $provider->provider, 'object_path' => $path, 'public_url' => $this->publicUrl($provider, $path),
            'status' => 'active', 'metadata' => [...$metadata, 'width' => $width, 'height' => $height, 'delivery_url_version' => 1], 'created_by_admin_id' => $admin->id,
        ]);
    }

    private function probe(MediaStorageProvider $provider): string
    {
        $marker = 'task-mall-storage-check-'.Str::uuid();
        $publicPath = 'media/health-check/'.Str::uuid().'.txt';
        $publicDisk = $this->disk($provider);
        try {
            $this->writeAndVerify($publicDisk, $publicPath, $marker, ['ContentType' => 'text/plain'], '公共素材桶');
            if ($provider->provider !== 'local') {
                $response = Http::connectTimeout(5)->timeout(15)->get($this->publicUrl($provider, $publicPath).'?storage_check='.Str::uuid());
                if (! $response->successful() || ! hash_equals($marker, $response->body())) {
                    throw new RuntimeException('公共素材域名无法匿名读取测试对象。');
                }
            }
        } finally {
            $publicDisk->delete($publicPath);
        }

        if ($provider->provider === 'local') {
            return '连接、写入、读取和删除均正常。';
        }

        $privatePath = 'quarantine/health-check/'.Str::uuid().'.txt';
        $privateDisk = $this->cloudDisk($provider, (string) $provider->private_bucket);
        try {
            $this->writeAndVerify($privateDisk, $privatePath, $marker, ['ContentType' => 'text/plain', 'visibility' => 'private'], '私有文件桶');
            $response = Http::connectTimeout(5)->timeout(15)->get($this->unsignedCloudUrl($provider, (string) $provider->private_bucket, $privatePath));
            if ($response->successful()) {
                throw new RuntimeException('私有文件桶允许匿名读取，请先收紧存储桶策略。');
            }
        } finally {
            $privateDisk->delete($privatePath);
        }

        return '公共素材可匿名读取，私有文件不可匿名读取，两个桶的写入、读取和删除均正常。';
    }

    /** @param array<string,mixed> $options */
    private function writeAndVerify(FilesystemAdapter $disk, string $path, string $contents, array $options, string $label): void
    {
        if (! $disk->put($path, $contents, $options)) {
            throw new RuntimeException($label.'测试对象写入失败。');
        }
        if (! $disk->exists($path) || ! hash_equals($contents, $disk->get($path))) {
            throw new RuntimeException($label.'测试对象写入后无法正确读取。');
        }
    }

    private function refreshPublicUrls(MediaStorageProvider $provider): void
    {
        MediaAsset::query()
            ->where('storage_provider', $provider->provider)
            ->select(['id', 'object_path'])
            ->chunkById(500, function ($assets) use ($provider): void {
                foreach ($assets as $asset) {
                    MediaAsset::query()->whereKey($asset->id)->update([
                        'public_url' => $this->publicUrl($provider, $asset->object_path),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    private function unsignedCloudUrl(MediaStorageProvider $provider, string $bucket, string $path): string
    {
        $parts = parse_url((string) $provider->endpoint);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$bucket.'.'.$parts['host'].$port.'/'.ltrim($path, '/');
    }

    /** @return array{host:string,port:int,ip:string} */
    private function assertPublicHttpsUrl(string $url): array
    {
        $parts = parse_url($url);
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['url' => '只允许不含账号信息的 HTTPS 图片地址。']);
        }
        $port = (int) ($parts['port'] ?? 443);
        if ($port !== 443) {
            throw ValidationException::withMessages(['url' => '链接转存只允许使用标准 HTTPS 端口。']);
        }
        $host = strtolower((string) $parts['host']);
        $addresses = $this->resolvePublicAddresses($host);
        if ($addresses === []) {
            throw ValidationException::withMessages(['url' => '图片域名无法解析。']);
        }
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages(['url' => '图片地址不能指向本机、内网或保留网络。']);
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /** @return array<int,string> */
    protected function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return collect(@dns_get_record($host, DNS_A | DNS_AAAA) ?: [])
            ->map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter(fn ($ip) => is_string($ip) && $ip !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @param array{host:string,port:int,ip:string} $target */
    private function requestPinnedRemoteImage(string $url, array $target): Response
    {
        if (! defined('CURLOPT_RESOLVE')) {
            throw ValidationException::withMessages(['url' => '当前服务器不支持安全的远程图片下载。']);
        }
        $resolvedIp = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
        $curlOptions = [constant('CURLOPT_RESOLVE') => ["{$target['host']}:{$target['port']}:{$resolvedIp}"]];
        if (defined('CURLOPT_PROXY')) {
            $curlOptions[constant('CURLOPT_PROXY')] = '';
        }

        try {
            return Http::connectTimeout(5)->timeout(20)->withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'curl' => $curlOptions,
            ])->get($url);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['url' => '图片下载失败，请确认网址可以公开访问。']);
        }
    }

    private function readRemoteBody(Response $response): string
    {
        $remote = $response->toPsrResponse()->getBody();
        $buffer = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (! is_resource($buffer)) {
            throw new RuntimeException('无法创建远程图片临时缓冲区。');
        }
        $size = 0;
        try {
            while (! $remote->eof()) {
                $chunk = $remote->read(min(64 * 1024, self::MAX_IMAGE_BYTES - $size + 1));
                if ($chunk === '' && ! $remote->eof()) {
                    throw new RuntimeException('远程图片读取中断。');
                }
                $size += strlen($chunk);
                if ($size > self::MAX_IMAGE_BYTES) {
                    throw ValidationException::withMessages(['url' => '远程图片不能超过 10MB。']);
                }
                if ($chunk !== '' && fwrite($buffer, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('远程图片临时写入失败。');
                }
            }
            rewind($buffer);
            $bytes = stream_get_contents($buffer);
            if ($bytes === false) {
                throw new RuntimeException('远程图片临时读取失败。');
            }

            return $bytes;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['url' => '图片下载过程中断，请稍后重试。']);
        } finally {
            $remote->close();
            fclose($buffer);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function normalizeCloudConfiguration(MediaStorageProvider $provider, array $data): array
    {
        foreach (['endpoint', 'region', 'bucket', 'private_bucket', 'public_base_url'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = trim((string) ($data[$field] ?? ''));
            $data[$field] = $value === '' ? null : (in_array($field, ['endpoint', 'public_base_url'], true) ? rtrim($value, '/') : $value);
        }
        if (! ($data['enabled'] ?? false)) {
            return $data;
        }

        $effective = [];
        foreach (['endpoint', 'region', 'bucket', 'private_bucket', 'public_base_url', 'access_key', 'secret_key'] as $field) {
            $effective[$field] = array_key_exists($field, $data) ? $data[$field] : $provider->{$field};
            if (blank($effective[$field])) {
                throw ValidationException::withMessages([$field => '启用 '.$provider->display_name.' 前必须填写 '.$field.'。']);
            }
        }
        foreach (['endpoint', 'public_base_url'] as $field) {
            $parts = parse_url((string) $effective[$field]);
            if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw ValidationException::withMessages([$field => $field.' 必须是不含账号、查询参数和片段的 HTTPS 地址。']);
            }
        }
        $endpointPath = (string) (parse_url((string) $effective['endpoint'], PHP_URL_PATH) ?? '');
        if ($endpointPath !== '' && $endpointPath !== '/') {
            throw ValidationException::withMessages(['endpoint' => 'S3 兼容端点不能包含路径。']);
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,79}$/', (string) $effective['region'])) {
            throw ValidationException::withMessages(['region' => 'Region 格式不正确。']);
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,118}[A-Za-z0-9]$/', (string) $effective['bucket'])) {
            throw ValidationException::withMessages(['bucket' => '存储桶或空间名称格式不正确。']);
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,118}[A-Za-z0-9]$/', (string) $effective['private_bucket'])) {
            throw ValidationException::withMessages(['private_bucket' => '私有存储桶或空间名称格式不正确。']);
        }
        if ($effective['bucket'] === $effective['private_bucket']) {
            throw ValidationException::withMessages(['private_bucket' => '私有文件与公共素材必须使用不同的存储桶或空间。']);
        }

        return $data;
    }

    /** @return array{public:bool,private:bool} */
    private function storedObjectState(MediaStorageProvider $provider): array
    {
        return [
            'public' => MediaAsset::query()->where('storage_provider', $provider->provider)->exists(),
            'private' => ControlledUploadedFile::query()->where('disk', $provider->provider)->exists(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array{public:bool,private:bool}  $storedObjects
     */
    private function assertStoredObjectTopologyChangeAllowed(MediaStorageProvider $provider, array $data, array $storedObjects): void
    {
        foreach (['endpoint', 'region'] as $field) {
            if (($storedObjects['public'] || $storedObjects['private']) && $this->fieldChanged($provider, $data, $field)) {
                throw ValidationException::withMessages([$field => '该存储仍有关联文件，不能直接更换 '.$field.'；请新建或迁移存储后再调整。']);
            }
        }
        if ($storedObjects['public'] && $this->fieldChanged($provider, $data, 'bucket')) {
            throw ValidationException::withMessages(['bucket' => '该存储仍有关联的公共素材，不能直接更换公共桶；请使用素材迁移功能。']);
        }
        if ($storedObjects['private'] && $this->fieldChanged($provider, $data, 'private_bucket')) {
            throw ValidationException::withMessages([
                'private_bucket' => '该存储仍有关联的私有文件，不能直接更换私有桶；请先迁移文件或保留原私有桶。',
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function configurationChanged(MediaStorageProvider $provider, array $data): bool
    {
        return collect(['endpoint', 'region', 'bucket', 'private_bucket', 'public_base_url', 'access_key', 'secret_key'])
            ->contains(fn (string $field): bool => $this->fieldChanged($provider, $data, $field));
    }

    /** @param array<string,mixed> $data */
    private function fieldChanged(MediaStorageProvider $provider, array $data, string $field): bool
    {
        return array_key_exists($field, $data) && (string) ($data[$field] ?? '') !== (string) ($provider->{$field} ?? '');
    }

    private function mask(?string $value): ?string
    {
        return filled($value) ? str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4) : null;
    }
}
