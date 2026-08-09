<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Operations\Services\ResilienceService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return ApiResponse::success(['status' => 'ok', 'service' => 'task-mall-api', 'timestamp' => now()->toIso8601String()]);
    }

    public function ready(ResilienceService $service): JsonResponse
    {
        $result = $service->readiness();

        return ApiResponse::success($result, $result['status'] === 'ready' ? 200 : 503);
    }
}
