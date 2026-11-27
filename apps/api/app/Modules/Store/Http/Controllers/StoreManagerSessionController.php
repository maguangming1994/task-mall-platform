<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreManagerSession;
use App\Modules\Store\Services\StoreManagerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerSessionController extends Controller
{
    public function store(Request $request, Store $store, StoreManagerAuthService $auth): JsonResponse
    {
        $payload = $auth->issueSession($request->user(), $store, $request->ip(), $request->userAgent());

        return ApiResponse::success($payload, 201, '已进入门店工作台。');
    }

    public function destroy(Request $request, StoreManagerAuthService $auth): JsonResponse
    {
        /** @var StoreManagerSession $session */
        $session = $request->attributes->get('store_session');
        $auth->logout($session);

        return ApiResponse::success(['revoked' => true]);
    }

    public function profile(Request $request, StoreManagerAuthService $auth): JsonResponse
    {
        /** @var Store $store */
        $store = $request->attributes->get('store');
        $member = $request->attributes->get('store_member');

        return ApiResponse::success([
            'store' => $auth->presentStore($store),
            'member' => $auth->presentMember($member),
        ]);
    }
}
