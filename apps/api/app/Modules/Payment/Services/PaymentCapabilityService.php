<?php

namespace App\Modules\Payment\Services;

use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Services\SystemSettingService;

class PaymentCapabilityService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /** @return array{wallet: array{enabled: bool, reason: ?string}, wechat: array{enabled: bool, reason: ?string}} */
    public function forPlatform(string $platform): array
    {
        $walletEnabled = $this->walletEnabled();
        $wechatEnabled = $this->wechatEnabled($platform);

        return [
            'wallet' => [
                'enabled' => $walletEnabled,
                'reason' => $walletEnabled ? null : '商城余额支付已由平台关闭，请选择微信支付。',
            ],
            'wechat' => [
                'enabled' => $wechatEnabled,
                'reason' => $wechatEnabled ? null : $this->wechatUnavailableReason($platform),
            ],
        ];
    }

    public function walletEnabled(): bool
    {
        try {
            return (bool) $this->settings->read('mall.wallet_payment_enabled')['value'];
        } catch (SettingException) {
            // 兼容尚未写入新配置定义的旧部署。
            return true;
        }
    }

    public function wechatEnabled(string $platform): bool
    {
        if (! in_array($platform, ['wechat-mini', 'h5-wechat', 'h5-browser'], true)) {
            return false;
        }

        if (config('wechat_payment.driver') === 'local') {
            return app()->environment(['local', 'testing']);
        }

        if (config('wechat_payment.driver') !== 'wechat') {
            return false;
        }

        $privateKeyPath = (string) config('wechat_payment.merchant_private_key_path');
        $platformCertificatePath = (string) config('wechat_payment.platform_certificate_path');
        $appId = $platform === 'wechat-mini'
            ? config('wechat_payment.mini_app_id')
            : config('wechat_payment.official_account_app_id');

        return filled(config('wechat_payment.merchant_id'))
            && filled(config('wechat_payment.merchant_certificate_serial'))
            && $privateKeyPath !== ''
            && is_file($privateKeyPath)
            && filled(config('wechat_payment.api_v3_key'))
            && $platformCertificatePath !== ''
            && is_file($platformCertificatePath)
            && filled(config('wechat_payment.notify_url'))
            && filled($appId);
    }

    public function wechatUnavailableReason(string $platform): string
    {
        return match ($platform) {
            'wechat-mini' => '微信小程序支付尚未配置完成，请联系平台管理员或改用余额支付。',
            'h5-wechat' => '微信公众号内微信支付尚未配置完成，请联系平台管理员或改用余额支付。',
            default => '浏览器微信支付尚未配置完成，请改用余额支付或稍后再试。',
        };
    }
}
