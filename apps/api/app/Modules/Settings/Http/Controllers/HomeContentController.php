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
            'floating_support' => 'homepage.floating_support',
            'modules' => 'homepage.modules',
        ];
        $content = [];
        $versions = [];

        foreach ($keys as $name => $settingKey) {
            $resolved = $settings->read($settingKey);
            $content[$name] = $resolved['value'];
            $versions[$name] = $resolved['version'];
        }

        $announcement = is_array($content['announcement'] ?? null) ? $content['announcement'] : [];
        $content['announcement'] = array_merge([
            'enabled' => true,
            'icon_text' => '公',
            'icon_url' => '',
            'title' => '平台公告',
            'content' => '',
            'link_type' => 'notifications',
        ], $announcement);

        $modules = is_array($content['modules'] ?? null) ? $content['modules'] : [];
        $priorityEntries = is_array($modules['priority_entries'] ?? null) ? $modules['priority_entries'] : [];
        if ($priorityEntries === []) {
            $priorityEntries = [
                ['id' => 'assets', 'mark' => '资', 'icon_url' => ''],
                ['id' => 'referrals', 'mark' => '邀', 'icon_url' => ''],
            ];
        }
        $modules['priority_entries'] = $priorityEntries;
        $content['modules'] = $modules;

        $floatingSupport = is_array($content['floating_support'] ?? null) ? $content['floating_support'] : [];
        if (! array_key_exists('qr_code_url', $floatingSupport)) {
            $floatingSupport['label'] = '客服二维码';
            $floatingSupport['subtitle'] = '扫码联系校园客服';
        }
        unset($floatingSupport['action_type'], $floatingSupport['phone'], $floatingSupport['external_url']);

        $content['floating_support'] = array_merge([
            'enabled' => true,
            'show_label' => true,
            'icon_text' => '服',
            'icon_url' => '',
            'label' => '客服二维码',
            'subtitle' => '扫码联系校园客服',
            'qr_code_url' => '',
            'qr_code_tip' => '长按二维码识别，或使用微信扫一扫',
            'position' => 'right',
            'bottom_offset_rpx' => 170,
        ], $floatingSupport);
        $content['announcement']['content'] = $richText->sanitize((string) $content['announcement']['content']);

        return ApiResponse::success([
            ...$content,
            'versions' => $versions,
        ]);
    }
}
