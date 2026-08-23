<?php

namespace App\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Modules\Auth\Services\WechatIdentityProvider;
use App\Modules\Operations\Services\ReleaseControlService;
use App\Modules\Payment\Services\PaymentCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientCapabilitiesController extends Controller
{
    public function show(
        Request $request,
        WechatIdentityProvider $wechat,
        ReleaseControlService $releases,
        PaymentCapabilityService $payments,
    ): JsonResponse {
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
        $paymentCapabilities = $payments->forPlatform($platform);
        $cashPaymentMethods = collect(['wechat', 'wallet'])
            ->filter(fn (string $method) => $paymentCapabilities[$method]['enabled'])
            ->values()
            ->all();

        return ApiResponse::success([
            'version' => 3,
            'platform' => $platform,
            'visitor_browsing' => true,
            'login_methods' => $loginMethods,
            'share_methods' => match ($platform) {
                'wechat-mini' => ['mini_card', 'qr_code'],
                'h5-wechat' => ['wechat_js', 'copy_link'],
                default => ['web_share', 'copy_link'],
            },
            'cash_payment_methods' => $cashPaymentMethods,
            'payment_methods' => [
                ['code' => 'wechat', 'label' => '微信全额支付', ...$paymentCapabilities['wechat']],
                ['code' => 'wallet', 'label' => '余额全额支付', ...$paymentCapabilities['wallet']],
            ],
            'wechat_payment_enabled' => $paymentCapabilities['wechat']['enabled'],
            'wallet_payment_enabled' => $paymentCapabilities['wallet']['enabled'],
            'external_task_publishing_enabled' => false,
            'external_task_publishing_stage' => 'closed',
            'h5_payment_status' => $platform === 'wechat-mini' ? 'not_applicable' : ($paymentCapabilities['wechat']['enabled'] ? 'enabled' : 'unavailable'),
            'payment_unavailable_message' => $cashPaymentMethods === [] ? '当前没有可用的现金支付方式，请联系平台管理员。' : null,
            'wechat_payment_unavailable_message' => $paymentCapabilities['wechat']['reason'],
            'wallet_payment_unavailable_message' => $paymentCapabilities['wallet']['reason'],
            'open_in_wechat_url' => null,
            'notification_channels' => ['in_app'],
            'feature_flags' => collect(['checkout_v2', 'community_video', 'external_notifications'])->mapWithKeys(fn (string $code) => [$code => $releases->enabled($code, (string) ($request->header('X-Device-Id') ?: 'anonymous'), $platform)]),
        ]);
    }
}
