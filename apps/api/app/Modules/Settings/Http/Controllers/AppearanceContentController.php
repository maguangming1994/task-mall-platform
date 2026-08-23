<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;

class AppearanceContentController extends Controller
{
    private const DEFAULTS = [
        'mall' => [
            'brand' => ['logo_url' => '', 'logo_text' => 'L', 'name' => '任务商城', 'caption' => 'TASK · REWARD · LIFE'],
            'assurances' => [
                ['id' => 'same_price', 'label' => '同端同价', 'description' => '各端价格保持一致', 'mark' => '价', 'icon_url' => '', 'tone' => 'yellow'],
                ['id' => 'payments', 'label' => '三种支付', 'description' => '现金、积分、混合', 'mark' => '付', 'icon_url' => '', 'tone' => 'cyan'],
                ['id' => 'after_sales', 'label' => '售后可查', 'description' => '退款进度完整留痕', 'mark' => '退', 'icon_url' => '', 'tone' => 'green'],
            ],
        ],
        'account' => [
            'profile_icon_url' => '',
            'service_icons' => [
                ['id' => 'tasks', 'mark' => '任', 'icon_url' => ''],
                ['id' => 'orders', 'mark' => '单', 'icon_url' => ''],
                ['id' => 'after_sales', 'mark' => '售', 'icon_url' => ''],
                ['id' => 'referrals', 'mark' => '邀', 'icon_url' => ''],
                ['id' => 'check_in', 'mark' => '签', 'icon_url' => ''],
                ['id' => 'notifications', 'mark' => '信', 'icon_url' => ''],
                ['id' => 'addresses', 'mark' => '址', 'icon_url' => ''],
                ['id' => 'legal', 'mark' => '约', 'icon_url' => ''],
                ['id' => 'security', 'mark' => '安', 'icon_url' => ''],
                ['id' => 'support', 'mark' => '服', 'icon_url' => ''],
            ],
        ],
    ];

    public function show(SystemSettingService $settings): JsonResponse
    {
        $result = [];
        $versions = [];
        foreach (['mall' => 'mall.appearance', 'account' => 'accounts.appearance'] as $name => $key) {
            try {
                $resolved = $settings->read($key);
                $value = is_array($resolved['value']) ? $resolved['value'] : [];
                $result[$name] = array_replace_recursive(self::DEFAULTS[$name], $value);
                $versions[$name] = $resolved['version'];
            } catch (SettingException) {
                $result[$name] = self::DEFAULTS[$name];
                $versions[$name] = null;
            }
        }

        return ApiResponse::success([...$result, 'versions' => $versions]);
    }
}
