<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\ProductionReadinessService;
use Illuminate\Http\JsonResponse;

class AdminProductionReadinessController extends Controller
{
    public function show(ProductionReadinessService $readiness): JsonResponse
    {
        return ApiResponse::success($readiness->evaluate());
    }
}
