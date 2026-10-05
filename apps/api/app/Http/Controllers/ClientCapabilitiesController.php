<?php

namespace App\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Modules\Auth\Services\WechatIdentityProvider;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Notification\Services\NotificationSceneConfigService;
use App\Modules\Operations\Services\ModuleGateService;
use App\Modules\Operations\Services\ReleaseControlService;
use App\Modules\Payment\Services\PaymentCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientCapabilitiesController extends Controller
{
    /**
     * 非模块的实验性开关：仍走全局灰度，不受校区覆盖影响。
     */
    private const EXPERIMENTAL_FLAG_CODES = ['checkout_v2', 'community_video', 'external_notifications'];

    public function show(
        Request $request,
        WechatIdentityProvider $wechat,
        ReleaseControlService $releases,
        ModuleGateService $gates,
        PaymentCapabilityService $payments,
        WechatProviderSettingsService $wechatSettings,
        NotificationSceneConfigService $notificationScenes,
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
        $notificationChannels = ['in_app'];
        if ($platform === 'wechat-mini' && collect($notificationScenes->catalog())->contains(fn (array $scene): bool => $scene['enabled'] && in_array('wechat_mini', $scene['channels'], true) && $scene['templates']['wechat_mini']['ready'])) {
            $notificationChannels[] = 'wechat_mini';
        }

        $subject = (string) ($request->header('X-Device-Id') ?: 'anonymous');
        $reviewing = $releases->enabled('review_in_progress', $subject, $platform);
        $reviewStatus = $reviewing ? 'reviewing' : 'reviewed';

        // 13 个功能模块按「全局开关 × 校区覆盖 × 父链」合并（与后端门禁同一口径）；
        // 校区上下文与门禁中间件一致：登录用户 campus_id 优先，否则取 X-Campus-Id 解析值。
        $resolvedCampusId = $gates->campusIdFromRequest($request);
        $moduleFlags = $gates->allEffective($resolvedCampusId, $subject, $platform);
        $experimentalFlags = collect(self::EXPERIMENTAL_FLAG_CODES)
            ->mapWithKeys(fn (string $code) => [$code => $releases->enabled($code, $subject, $platform)])
            ->all();

        return ApiResponse::success([
            'version' => 5,
            // 本次模块判定实际使用的校区（public_id；登录用户以账号校区为准），无校区上下文时为 null
            'campus_id' => $gates->campusPublicIdFromRequest($request),
            'platform' => $platform,
            'visitor_browsing' => true,
            'login_methods' => $loginMethods,
            'wechat_official_account_app_id' => $platform === 'h5-wechat'
                ? $wechatSettings->effective('official_account')['app_id']
                : null,
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
            'notification_channels' => $notificationChannels,
            'review_status' => $reviewStatus,
            'feature_flags' => $moduleFlags + $experimentalFlags,
            'module_placeholders' => $releases->modulePlaceholders($reviewStatus),
        ]);
    }
}
