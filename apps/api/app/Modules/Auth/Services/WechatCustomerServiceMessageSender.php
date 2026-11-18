<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\UserIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 公众号客服消息。
 *
 * 微信限制：用户与公众号最近一次互动后 48 小时内才能收到客服消息。
 * 超出时限或用户未关注/未授权时按跳过处理，不影响站内工单回复。
 */
class WechatCustomerServiceMessageSender
{
    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    /** @return array{status:'sent'|'skipped'|'failed',message:string} */
    public function send(User $user, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['status' => 'skipped', 'message' => '客服消息内容为空。'];
        }
        $configuration = $this->settings->effective('official_account');
        if ($configuration['driver'] !== 'wechat' || $configuration['app_id'] === '' || blank($configuration['app_secret'])) {
            return ['status' => 'skipped', 'message' => '公众号尚未配置，未发送微信客服消息。'];
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
            return ['status' => 'skipped', 'message' => '用户未在公众号内授权登录，未发送微信客服消息。'];
        }

        try {
            return $this->request((string) $identity->provider_user_value, $content, false, (int) $configuration['timeout_seconds']);
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'message' => Str::limit($exception->getMessage(), 200)];
        }
    }

    /** @return array{status:'sent'|'skipped'|'failed',message:string} */
    private function request(string $openid, string $content, bool $refreshed, int $timeout): array
    {
        try {
            $response = Http::acceptJson()->timeout($timeout)
                ->withQueryParameters(['access_token' => $this->tokens->token('official_account', $refreshed)])
                ->post('https://api.weixin.qq.com/cgi-bin/message/custom/send', [
                    'touser' => $openid,
                    'msgtype' => 'text',
                    'text' => ['content' => $content],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('公众号客服消息接口连接失败。', 0, $exception);
        }
        $payload = $response->json();
        $code = is_array($payload) ? (int) ($payload['errcode'] ?? -1) : -1;
        if ($code === 0) {
            return ['status' => 'sent', 'message' => '微信客服消息已发送。'];
        }
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->request($openid, $content, true, $timeout);
        }
        if (in_array($code, [45015, 40003], true)) {
            return ['status' => 'skipped', 'message' => '用户最近 48 小时未与公众号互动，微信不允许发送客服消息。'];
        }

        throw new RuntimeException('公众号客服消息发送失败（'.$code.'）。');
    }
}
