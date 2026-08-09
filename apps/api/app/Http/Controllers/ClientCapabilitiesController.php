<?php

namespace App\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Modules\Auth\Services\WechatIdentityProvider;
use App\Modules\Operations\Services\ReleaseControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientCapabilitiesController extends Controller
{
    public function show(Request $request, WechatIdentityProvider $wechat, ReleaseControlService $releases): JsonResponse
    {
        $data = validator([
            'client_platform' => $request->header('X-Client-Platform'),
        ], [
            'client_platform' => ['required', 'in:wechat-mini,h5-wechat,h5-browser'],
        ])->validate();
        $platform = $data['client_platform'];
        $loginMethods = ['phone'];
        if ($platform === 'wechat-mini' && $wechat->available('wechat_mini')) {
            array_unshift($loginMethods, 'wechat_mini');
        }
        if ($platform === 'h5-wechat' && $wechat->available('wechat_oa')) {
            array_unshift($loginMethods, 'wechat_oa');
        }

        return ApiResponse::success([
            'version' => 2,
            'platform' => $platform,
            'visitor_browsing' => true,
            'login_methods' => $loginMethods,
            'share_methods' => match ($platform) {
                'wechat-mini' => ['mini_card', 'qr_code'],
                'h5-wechat' => ['wechat_js', 'copy_link'],
                default => ['web_share', 'copy_link'],
            },
            'cash_payment_methods' => ['wallet'],
            'wechat_payment_enabled' => false,
            'wallet_payment_enabled' => true,
            'external_task_publishing_enabled' => false,
            'external_task_publishing_stage' => 'closed',
            'h5_payment_status' => $platform === 'wechat-mini' ? 'not_applicable' : 'pending_permission',
            'payment_unavailable_message' => '微信支付将在取得商户权限后开放。',
            'open_in_wechat_url' => null,
            'notification_channels' => ['in_app'],
            'feature_flags' => collect(['checkout_v2', 'community_video', 'external_notifications'])->mapWithKeys(fn (string $code) => [$code => $releases->enabled($code, (string) ($request->header('X-Device-Id') ?: 'anonymous'), $platform)]),
        ]);
    }
}
