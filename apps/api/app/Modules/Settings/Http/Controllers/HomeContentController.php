<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Common\Support\RichTextSanitizer;
use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;

class HomeContentController extends Controller
{
    public function show(SystemSettingService $settings, RichTextSanitizer $richText): JsonResponse
    {
        $keys = [
            'brand' => 'homepage.brand',
            'carousel' => 'homepage.carousel',
            'announcement' => 'homepage.announcement',
            'popup' => 'homepage.popup',
            'modules' => 'homepage.modules',
        ];
        $content = [];
        $versions = [];

        foreach ($keys as $name => $settingKey) {
            $resolved = $settings->read($settingKey);
            $content[$name] = $resolved['value'];
            $versions[$name] = $resolved['version'];
        }
        $content['announcement']['content'] = $richText->sanitize((string) ($content['announcement']['content'] ?? ''));

        return ApiResponse::success([
            ...$content,
            'versions' => $versions,
        ]);
    }
}
