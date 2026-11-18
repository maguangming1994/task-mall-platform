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

/**
 * 公众号订阅通知。
 *
 * 用户在公众号内授权登录后即留有 openid，按场景模板配置发送订阅通知；
 * 未在公众号内登录过或未订阅模板时按跳过处理，不影响站内信。
 */
class WechatOfficialAccountSubscriptionSender
{
    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    /** @param array<string,mixed> $payload */
    public function send(User $user, array $payload): string
    {
        $configuration = $this->settings->effective('official_account');
        if ($configuration['driver'] === 'local') {
            if (! app()->environment(['local', 'testing'])) {
                throw new PermanentNotificationDeliveryException('WECHAT_OA_NOT_CONFIGURED', '生产环境尚未启用真实微信公众号接口。');
            }

            return 'local-wechat-oa-'.Str::ulid();
        }
        if ($configuration['app_id'] === '' || blank($configuration['app_secret'])) {
            throw new PermanentNotificationDeliveryException('WECHAT_OA_NOT_CONFIGURED', '微信公众号凭据尚未配置完整。');
        }

        $appIdHash = hash('sha256', (string) $configuration['app_id']);
        $identity = UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', 'wechat_oa')
            ->whereNotNull('provider_user_value')
            ->orderByDesc('last_login_at')
            ->get()
            ->first(fn (UserIdentity $candidate): bool => is_string(data_get($candidate->metadata, 'app_id_hash'))
                && hash_equals($appIdHash, (string) data_get($candidate->metadata, 'app_id_hash')));
        if ($identity === null) {
            throw new SkippedNotificationDeliveryException('WECHAT_OA_IDENTITY_MISSING', '用户尚未在公众号内授权登录，无法发送订阅通知。');
        }
        $openid = (string) $identity->provider_user_value;
        $templateId = trim((string) ($payload['provider_template_id'] ?? ''));
        $data = $payload['provider_data'] ?? null;
        if ($templateId === '' || ! is_array($data) || $data === []) {
            throw new PermanentNotificationDeliveryException('WECHAT_OA_TEMPLATE_INVALID', '公众号订阅通知模板配置不完整。');
        }

        return $this->request(
            $openid,
            $templateId,
            $data,
            (string) ($payload['url'] ?? ''),
            false,
            (int) $configuration['timeout_seconds'],
        );
    }

    /** @param array<string,mixed> $data */
    private function request(string $openid, string $templateId, array $data, string $url, bool $refreshed, int $timeout): string
    {
        try {
            $response = Http::acceptJson()->timeout($timeout)
                ->withQueryParameters(['access_token' => $this->tokens->token('official_account', $refreshed)])
                ->post('https://api.weixin.qq.com/cgi-bin/message/subscribe/bizsend', array_filter([
                    'touser' => $openid,
                    'template_id' => $templateId,
                    'url' => $url,
                    'data' => $data,
                ], static fn ($value): bool => $value !== ''));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('公众号订阅通知接口连接失败。', 0, $exception);
        }
        $result = $response->json();
        $code = is_array($result) ? (int) ($result['errcode'] ?? -1) : -1;
        if ($code === 0) {
            return (string) (($result['msgid'] ?? null) ?: 'wechat-oa-'.Str::ulid());
        }
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->request($openid, $templateId, $data, $url, true, $timeout);
        }
        if ($code === 43101) {
            throw new SkippedNotificationDeliveryException('WECHAT_SUBSCRIPTION_NOT_AVAILABLE', '用户未订阅该公众号通知模板。');
        }
        if (in_array($code, [40003, 40037, 47003], true)) {
            throw new PermanentNotificationDeliveryException('WECHAT_'.$code, '公众号订阅通知模板或接收参数无效。');
        }
        if ($response->clientError()) {
            throw new PermanentNotificationDeliveryException('WECHAT_'.$code, '公众号订阅通知请求被拒绝。');
        }

        throw new RuntimeException('公众号订阅通知服务暂时不可用（'.$code.'）。');
    }
}
