<?php

namespace App\Modules\Campus\Services;

use App\Modules\Media\Models\MediaAsset;

class CampusBrandingUrl
{
    public function resolve(?string $assetId, ?MediaAsset $asset, ?string $fallback): ?string
    {
        if (! filled($assetId)) {
            return $fallback;
        }
        if ($asset?->status !== 'active' || ! filled($asset->public_url)) {
            return null;
        }

        // Older local imports persisted the server IP before the HTTPS domain existed.
        // Only rewrite paths proven to belong to this local asset, never external URLs.
        $path = '/storage/'.ltrim((string) $asset->object_path, '/');
        if ($asset->storage_provider === 'local'
            && parse_url($asset->public_url, PHP_URL_SCHEME) === 'http'
            && parse_url($asset->public_url, PHP_URL_PATH) === $path
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')) {
            return rtrim((string) config('app.url'), '/').$path;
        }

        return $asset->public_url;
    }
}
