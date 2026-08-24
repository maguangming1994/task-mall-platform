<?php

namespace App\Modules\Upload\Services;

use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Upload\Exceptions\UploadException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class ControlledUploadDiskResolver
{
    private const CLOUD_PROVIDERS = ['tencent_cos', 'qiniu_kodo'];

    public function activeDiskName(): string
    {
        $provider = MediaStorageProvider::query()
            ->where('active', true)
            ->where('enabled', true)
            ->first();

        if ($provider === null) {
            return $this->legacyDiskName();
        }

        if ($provider->provider === 'local') {
            return $this->legacyDiskName();
        }

        if (! in_array($provider->provider, self::CLOUD_PROVIDERS, true)) {
            throw new UploadException('UPLOAD_STORAGE_UNAVAILABLE', '当前启用的存储不支持受控私有上传。', 503);
        }

        $this->cloudConfiguration($provider);

        return $provider->provider;
    }

    public function disk(string $providerName): Filesystem
    {
        if (in_array($providerName, ['local', 's3'], true)) {
            return Storage::disk($providerName);
        }

        $provider = MediaStorageProvider::query()->where('provider', $providerName)->first();
        if ($provider === null || ! in_array($provider->provider, self::CLOUD_PROVIDERS, true)) {
            throw new UploadException('UPLOAD_STORAGE_UNAVAILABLE', '受控私有上传存储不存在。', 503);
        }

        return Storage::build($this->cloudConfiguration($provider));
    }

    /** @return array<string,mixed> */
    private function cloudConfiguration(MediaStorageProvider $provider): array
    {
        foreach (['endpoint', 'region', 'private_bucket', 'access_key', 'secret_key'] as $required) {
            if (blank($provider->{$required})) {
                throw new UploadException('UPLOAD_STORAGE_UNAVAILABLE', '当前启用的云存储配置不完整。', 503);
            }
        }

        if ($provider->private_bucket === $provider->bucket) {
            throw new UploadException('UPLOAD_STORAGE_UNAVAILABLE', '受控私有上传不能与公共素材共用存储桶。', 503);
        }

        if (strtolower((string) parse_url((string) $provider->endpoint, PHP_URL_SCHEME)) !== 'https') {
            throw new UploadException('UPLOAD_STORAGE_UNAVAILABLE', '受控私有上传只允许使用 HTTPS 云存储端点。', 503);
        }

        return [
            'driver' => 's3',
            'key' => $provider->access_key,
            'secret' => $provider->secret_key,
            'region' => $provider->region,
            'bucket' => $provider->private_bucket,
            'endpoint' => $provider->endpoint,
            'use_path_style_endpoint' => false,
            'throw' => true,
        ];
    }

    private function legacyDiskName(): string
    {
        $disk = (string) config('uploads.disk', 'local');
        if (! in_array($disk, ['local', 's3'], true)) {
            throw new UploadException('UPLOAD_STORAGE_UNAVAILABLE', '旧版受控上传磁盘配置无效。', 503);
        }

        return $disk;
    }
}
