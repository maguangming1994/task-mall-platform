<?php

namespace App\Modules\Payment\Services;

use App\Modules\Settings\Exceptions\SettingException;
use App\Modules\Settings\Services\SystemSettingService;

class PaymentCapabilityService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly WechatPaymentSettingsService $wechatPaymentSettings,
    ) {}

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

        $configuration = $this->wechatPaymentSettings->effective();
        if ($configuration['driver'] === 'local') {
            return app()->environment(['local', 'testing']);
        }

        if ($configuration['driver'] !== 'wechat') {
            return false;
        }

        $appId = $platform === 'wechat-mini'
            ? $configuration['mini_app_id']
            : $configuration['official_account_app_id'];

        return filled($configuration['merchant_id'])
            && filled($configuration['merchant_certificate_serial'])
            && openssl_pkey_get_private((string) $configuration['merchant_private_key']) !== false
            && filled($configuration['api_v3_key'])
            && openssl_x509_read((string) $configuration['platform_certificate']) !== false
            && filled($configuration['notify_url'])
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
