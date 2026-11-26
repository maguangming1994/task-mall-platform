<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\PageDecorationService;
use Illuminate\Http\JsonResponse;

class PageDecorationController extends Controller
{
    public function show(PageDecorationService $decoration): JsonResponse
    {
        return ApiResponse::success($decoration->published());
    }
}
