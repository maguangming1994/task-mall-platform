<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;

class AppNavigationController extends Controller
{
    public function show(SystemSettingService $settings): JsonResponse
    {
        $resolved = $settings->read('navigation.bottom_tabs');

        return ApiResponse::success([
            ...$resolved['value'],
            'version' => $resolved['version'],
        ]);
    }
}
