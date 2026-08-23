<?php

namespace App\Modules\Growth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Growth\Services\DailyCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyCheckInController extends Controller
{
    public function show(Request $request, DailyCheckInService $checkIns): JsonResponse
    {
        return ApiResponse::success($checkIns->status($request->user()));
    }

    public function store(Request $request, DailyCheckInService $checkIns): JsonResponse
    {
        return ApiResponse::success($checkIns->checkIn($request->user()), 201);
    }
}
