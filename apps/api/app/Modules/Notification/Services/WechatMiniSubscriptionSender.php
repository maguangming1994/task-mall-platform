<?php

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Notification\Exceptions\PermanentNotificationDeliveryException;
use App\Modules\Notification\Exceptions\SkippedNotificationDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WechatMiniSubscriptionSender
{
    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    /** @param array<string,mixed> $payload */
    public function send(User $user, array $payload): string
    {
        $configuration = $this->settings->effective('mini');
        if ($configuration['driver'] === 'local') {
            if (! app()->environment(['local', 'testing'])) {
                throw new PermanentNotificationDeliveryException('WECHAT_MINI_NOT_CONFIGURED', '生产环境尚未启用真实微信小程序接口。');
            }

            return 'local-wechat-mini-'.Str::ulid();
        }
        $identityId = (int) ($payload['wechat_identity_id'] ?? 0);
        $appIdHash = hash('sha256', (string) $configuration['app_id']);
        if ($identityId < 1
            || ! is_string($payload['wechat_app_id_hash'] ?? null)
            || ! hash_equals($appIdHash, (string) $payload['wechat_app_id_hash'])) {
            throw new SkippedNotificationDeliveryException('WECHAT_MINI_IDENTITY_SCOPE_CHANGED', '小程序配置或授权身份已经变更，请重新授权订阅消息。');
        }
        $identity = UserIdentity::query()
            ->whereKey($identityId)
            ->where('user_id', $user->id)
            ->where('provider', 'wechat_mini')
            ->first();
        if ($identity === null
            || ! is_string(data_get($identity->metadata, 'app_id_hash'))
            || ! hash_equals($appIdHash, (string) data_get($identity->metadata, 'app_id_hash'))) {
            throw new SkippedNotificationDeliveryException('WECHAT_MINI_IDENTITY_SCOPE_CHANGED', '小程序配置或授权身份已经变更，请重新授权订阅消息。');
        }
        $openid = $identity?->provider_user_value;
        if (! is_string($openid) || $openid === '') {
            throw new SkippedNotificationDeliveryException('WECHAT_MINI_IDENTITY_MISSING', '用户尚未绑定微信小程序身份。');
        }
        $templateId = trim((string) ($payload['provider_template_id'] ?? ''));
        $data = $payload['provider_data'] ?? null;
        if ($templateId === '' || ! is_array($data) || $data === []) {
            throw new PermanentNotificationDeliveryException('WECHAT_MINI_TEMPLATE_INVALID', '微信订阅模板配置不完整。');
        }

        return $this->request($openid, $templateId, $data, (string) ($payload['page'] ?? ''), false, (int) $configuration['timeout_seconds']);
    }

    /** @param array<string,mixed> $data */
    private function request(string $openid, string $templateId, array $data, string $page, bool $refreshed, int $timeout): string
    {
        try {
            $response = Http::acceptJson()->timeout($timeout)
                ->withQueryParameters(['access_token' => $this->tokens->token('mini', $refreshed)])
                ->post('https://api.weixin.qq.com/cgi-bin/message/subscribe/send', array_filter([
                    'touser' => $openid,
                    'template_id' => $templateId,
                    'page' => $page,
                    'miniprogram_state' => 'formal',
                    'lang' => 'zh_CN',
                    'data' => $data,
                ], static fn ($value): bool => $value !== ''));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('微信订阅消息接口连接失败。', 0, $exception);
        }
        $result = $response->json();
        $code = is_array($result) ? (int) ($result['errcode'] ?? -1) : -1;
        if ($code === 0) {
            return (string) (($result['msgid'] ?? null) ?: 'wechat-mini-'.Str::ulid());
        }
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->request($openid, $templateId, $data, $page, true, $timeout);
        }
        if ($code === 43101) {
            throw new SkippedNotificationDeliveryException('WECHAT_SUBSCRIPTION_NOT_AVAILABLE', '用户未保留该模板的可用订阅授权。');
        }
        if (in_array($code, [40003, 40037, 47003], true)) {
            throw new PermanentNotificationDeliveryException('WECHAT_'.$code, '微信订阅模板或接收参数无效。');
        }
        if ($response->clientError()) {
            throw new PermanentNotificationDeliveryException('WECHAT_'.$code, '微信订阅消息请求被拒绝。');
        }

        throw new RuntimeException('微信订阅消息服务暂时不可用（'.$code.'）。');
    }
}
