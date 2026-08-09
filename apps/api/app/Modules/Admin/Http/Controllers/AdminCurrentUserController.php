<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Services\AdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCurrentUserController extends Controller
{
    public function show(Request $request, AdminAuthService $auth): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($auth->present($admin));
    }

    public function navigation(Request $request, AdminAuthService $auth): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($auth->navigation($admin));
    }
}
