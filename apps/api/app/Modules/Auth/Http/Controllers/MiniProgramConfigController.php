<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * 公开的小程序基础配置端点。
 *
 * 后台「第三方服务配置」保存的小程序 AppID 是唯一数据源；AppID 本身会随已发布的
 * 小程序包公开分发（OAuth 跳转、体验版二维码中均可见），不属于机密，可公开读取。
 * 本端点只暴露 AppID 与登录驱动，绝不返回 app_secret 等凭据字段，
 * 供小程序发布脚本与构建流水线自动读取，避免人工重复传递。
 */
class MiniProgramConfigController extends Controller
{
    public function show(WechatProviderSettingsService $settings): JsonResponse
    {
        $mini = $settings->effective('mini');

        return ApiResponse::success([
            'mini_program_appid' => $mini['app_id'],
            'login_driver' => $mini['driver'],
        ]);
    }
}
