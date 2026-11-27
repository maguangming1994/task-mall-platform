<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Media\Services\MediaStorageService;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerMediaController extends Controller
{
    use ResolvesStoreContext;

    public function upload(Request $request, MediaStorageService $storage): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp']]);
        $asset = $storage->upload($request->file('file'), $request->user());

        return ApiResponse::success($storage->presentAsset($asset), 201);
    }

    public function importUrl(Request $request, MediaStorageService $storage): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2048']]);
        $asset = $storage->importUrl($data['url'], $request->user());

        return ApiResponse::success($storage->presentAsset($asset), 201);
    }
}
