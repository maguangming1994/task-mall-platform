<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\AuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WechatIdentityProvider
{
    /**
     * @return array{openid: string, unionid: ?string}
     */
    public function exchange(string $channel, string $code): array
    {
        if (config('wechat_auth.driver') === 'local') {
            return $this->exchangeLocally($code);
        }

        if (config('wechat_auth.driver') !== 'wechat') {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务当前不可用。', 503);
        }

        return $channel === 'wechat_mini'
            ? $this->exchangeMiniCode($code)
            : $this->exchangeOfficialAccountCode($code);
    }

    public function available(string $channel): bool
    {
        if (config('wechat_auth.driver') === 'local') {
            return app()->environment(['local', 'testing']);
        }

        $configKey = $channel === 'wechat_mini' ? 'mini' : 'official_account';

        return config('wechat_auth.driver') === 'wechat'
            && filled(config("wechat_auth.{$configKey}.app_id"))
            && filled(config("wechat_auth.{$configKey}.app_secret"));
    }

    /** @return array{openid: string, unionid: ?string} */
    private function exchangeLocally(string $code): array
    {
        if (! app()->environment(['local', 'testing'])
            || ! preg_match('/^local:([A-Za-z0-9_-]{3,80})(?::([A-Za-z0-9_-]{3,80}))?$/', $code, $matches)) {
            throw new AuthException('WECHAT_AUTH_FAILED', '微信临时登录凭证无效或已过期。', 422);
        }

        return ['openid' => $matches[1], 'unionid' => $matches[2] ?? null];
    }

    /** @return array{openid: string, unionid: ?string} */
    private function exchangeMiniCode(string $code): array
    {
        $this->ensureAvailable('wechat_mini');

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('wechat_auth.timeout_seconds'))
                ->get('https://api.weixin.qq.com/sns/jscode2session', [
                    'appid' => config('wechat_auth.mini.app_id'),
                    'secret' => config('wechat_auth.mini.app_secret'),
                    'js_code' => $code,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务连接失败，请稍后重试。', 503);
        }

        if ($response->serverError()) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务暂时不可用。', 503);
        }

        return $this->validatedPayload($response->json());
    }

    /** @return array{openid: string, unionid: ?string} */
    private function exchangeOfficialAccountCode(string $code): array
    {
        $this->ensureAvailable('wechat_oa');

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('wechat_auth.timeout_seconds'))
                ->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
                    'appid' => config('wechat_auth.official_account.app_id'),
                    'secret' => config('wechat_auth.official_account.app_secret'),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务连接失败，请稍后重试。', 503);
        }

        if ($response->serverError()) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务暂时不可用。', 503);
        }

        return $this->validatedPayload($response->json());
    }

    /** @param array<string, mixed>|null $payload
     * @return array{openid: string, unionid: ?string}
     */
    private function validatedPayload(?array $payload): array
    {
        if (! is_array($payload) || ! is_string($payload['openid'] ?? null)) {
            throw new AuthException('WECHAT_AUTH_FAILED', '微信临时登录凭证无效或已过期。', 422);
        }

        return [
            'openid' => $payload['openid'],
            'unionid' => is_string($payload['unionid'] ?? null) ? $payload['unionid'] : null,
        ];
    }

    private function ensureAvailable(string $channel): void
    {
        if (! $this->available($channel)) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务尚未完成生产配置。', 503);
        }
    }
}
