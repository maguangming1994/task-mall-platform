<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Media\Services\MediaStorageService;
use App\Modules\Task\Models\TaskPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskPublisherMediaController extends Controller
{
    public function upload(Request $request, MediaStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp']]);
        $publisher = $this->publisher($request);
        $asset = $storage->upload($request->file('file'), $publisher->adminUser);
        $presented = $storage->presentAsset($asset);
        $audit->record($publisher->adminUser, 'media.asset.publisher_upload', 'media_asset', $asset->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    public function importUrl(Request $request, MediaStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url:https', 'max:2048']]);
        $publisher = $this->publisher($request);
        $asset = $storage->importUrl($data['url'], $publisher->adminUser);
        $presented = $storage->presentAsset($asset);
        $audit->record($publisher->adminUser, 'media.asset.publisher_import_url', 'media_asset', $asset->public_id, null, $presented);

        return ApiResponse::success($presented, 201);
    }

    private function publisher(Request $request): TaskPublisher
    {
        /** @var TaskPublisher $publisher */
        $publisher = $request->user();

        return $publisher->loadMissing('adminUser');
    }
}
