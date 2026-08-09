<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function show(Request $request, AuthService $auth): JsonResponse
    {
        return ApiResponse::success($auth->presentUser($request->user()));
    }
}
