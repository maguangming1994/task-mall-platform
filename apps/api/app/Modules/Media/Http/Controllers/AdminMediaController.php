<?php

namespace App\Modules\Media\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Media\Models\MediaStorageProvider;
use App\Modules\Media\Services\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMediaController extends Controller
{
    public function providers(MediaStorageService $storage): JsonResponse
    {
        return ApiResponse::success(['items' => $storage->providers()]);
    }

    public function configure(Request $request, MediaStorageProvider $mediaStorageProvider, MediaStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'endpoint' => ['nullable', 'url:https', 'max:500'],
            'region' => ['nullable', 'string', 'max:80'],
            'bucket' => ['nullable', 'string', 'max:120'],
            'public_base_url' => ['nullable', 'url:http,https', 'max:500'],
            'access_key' => ['nullable', 'string', 'max:500'],
            'secret_key' => ['nullable', 'string', 'max:1000'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $storage->providers();
        $updated = $storage->configure($mediaStorageProvider, $data);
        $audit->record($admin, 'media.storage.configure', 'media_storage_provider', $updated->provider, $before, $storage->providers());

        return ApiResponse::success($storage->providers());
    }

    public function test(Request $request, MediaStorageProvider $mediaStorageProvider, MediaStorageService $storage, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $storage->test($mediaStorageProvider);
        $audit->record($admin, 'media.storage.test', 'media_storage_provider', $mediaStorageProvider->provider, null, $result);

        return ApiResponse::success($result);
    }

    public function activate(Request $request, MediaStorageProvider $mediaStorageProvider, MediaStorageService $storage, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $updated = $storage->activate($mediaStorageProvider);
        $audit->record($admin, 'media.storage.activate', 'media_storage_provider', $updated->provider, null, ['active' => true]);

        return ApiResponse::success(['items' => $storage->providers()]);
    }

    public function upload(Request $request, MediaStorageService $storage): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp']]);
        /** @var AdminUser $admin */ $admin = $request->user();

        return ApiResponse::success($storage->presentAsset($storage->upload($request->file('file'), $admin)), 201);
    }

    public function importUrl(Request $request, MediaStorageService $storage): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url:https', 'max:2048']]);
        /** @var AdminUser $admin */ $admin = $request->user();

        return ApiResponse::success($storage->presentAsset($storage->importUrl($data['url'], $admin)), 201);
    }

    public function migrations(MediaStorageService $storage): JsonResponse
    {
        return ApiResponse::success(['items' => $storage->migrations()]);
    }

    public function migrate(Request $request, MediaStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['target_provider' => ['required', 'in:local,tencent_cos,qiniu_kodo'], 'delete_source' => ['sometimes', 'boolean']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $migration = $storage->startMigration($data['target_provider'], (bool) ($data['delete_source'] ?? false), $admin);
        $audit->record($admin, 'media.storage.migrate', 'media_storage_migration', $migration->public_id, null, $storage->presentMigration($migration));

        return ApiResponse::success($storage->presentMigration($migration), 202);
    }
}
