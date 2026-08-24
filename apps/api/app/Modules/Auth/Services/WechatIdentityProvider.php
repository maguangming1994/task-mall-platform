<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\AuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WechatIdentityProvider
{
    public function __construct(private readonly WechatProviderSettingsService $settings) {}

    /** @return array{openid:string,unionid:?string,app_id_hash:string} */
    public function exchange(string $channel, string $code): array
    {
        $provider = $this->providerName($channel);
        $configuration = $this->settings->effective($provider);
        if ($configuration['driver'] !== 'local' && $configuration['driver'] !== 'wechat') {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务当前不可用。', 503);
        }

        $identity = $configuration['driver'] === 'local'
            ? $this->exchangeLocally($code)
            : ($provider === 'mini'
                ? $this->exchangeMiniCode($code, $configuration)
                : $this->exchangeOfficialAccountCode($code, $configuration));

        return [...$identity, 'app_id_hash' => hash('sha256', (string) $configuration['app_id'])];
    }

    public function available(string $channel): bool
    {
        $configuration = $this->settings->effective($this->providerName($channel));
        if ($configuration['driver'] === 'local') {
            return app()->environment(['local', 'testing']);
        }

        return $configuration['driver'] === 'wechat'
            && filled($configuration['app_id']) && filled($configuration['app_secret']);
    }

    public function exchangePhoneNumber(string $code): string
    {
        $configuration = $this->settings->effective('mini');
        if ($configuration['driver'] !== 'wechat' || blank($configuration['app_id']) || blank($configuration['app_secret'])) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信手机号授权服务尚未完成生产配置。', 503);
        }
        try {
            $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                ->withQueryParameters(['access_token' => $this->miniAccessToken($configuration)])
                ->post('https://api.weixin.qq.com/wxa/business/getuserphonenumber', ['code' => $code]);
        } catch (ConnectionException) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信手机号授权服务连接失败，请稍后重试。', 503);
        }
        $payload = $response->json();
        $phone = is_array($payload) ? data_get($payload, 'phone_info.phoneNumber') : null;
        if ($response->serverError()) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信手机号授权服务暂时不可用。', 503);
        }
        if (! is_array($payload) || ($payload['errcode'] ?? null) !== 0
            || ! is_string($phone) || ! preg_match('/^\+?[0-9]{6,20}$/', $phone)) {
            throw new AuthException('WECHAT_PHONE_AUTH_FAILED', '微信手机号授权凭证无效或已过期，请重新授权。', 422);
        }

        return str_starts_with($phone, '+86') ? substr($phone, 3) : $phone;
    }

    /** @return array{openid:string,unionid:?string} */
    private function exchangeLocally(string $code): array
    {
        if (! app()->environment(['local', 'testing'])
            || ! preg_match('/^local:([A-Za-z0-9_-]{3,80})(?::([A-Za-z0-9_-]{3,80}))?$/', $code, $matches)) {
            throw new AuthException('WECHAT_AUTH_FAILED', '微信临时登录凭证无效或已过期。', 422);
        }

        return ['openid' => $matches[1], 'unionid' => $matches[2] ?? null];
    }

    /** @param array<string,mixed> $configuration
     * @return array{openid:string,unionid:?string}
     */
    private function exchangeMiniCode(string $code, array $configuration): array
    {
        try {
            $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                ->get('https://api.weixin.qq.com/sns/jscode2session', [
                    'appid' => $configuration['app_id'], 'secret' => $configuration['app_secret'],
                    'js_code' => $code, 'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务连接失败，请稍后重试。', 503);
        }
        if ($response->serverError()) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务暂时不可用。', 503);
        }

        return $this->validatedPayload($response->json());
    }

    /** @param array<string,mixed> $configuration
     * @return array{openid:string,unionid:?string}
     */
    private function exchangeOfficialAccountCode(string $code, array $configuration): array
    {
        try {
            $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                ->get('https://api.weixin.qq.com/sns/oauth2/access_token', [
                    'appid' => $configuration['app_id'], 'secret' => $configuration['app_secret'],
                    'code' => $code, 'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务连接失败，请稍后重试。', 503);
        }
        if ($response->serverError()) {
            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务暂时不可用。', 503);
        }

        return $this->validatedPayload($response->json());
    }

    /** @param array<string,mixed>|null $payload
     * @return array{openid:string,unionid:?string}
     */
    private function validatedPayload(?array $payload): array
    {
        if (! is_array($payload) || ! is_string($payload['openid'] ?? null)) {
            throw new AuthException('WECHAT_AUTH_FAILED', '微信临时登录凭证无效或已过期。', 422);
        }

        return ['openid' => $payload['openid'], 'unionid' => is_string($payload['unionid'] ?? null) ? $payload['unionid'] : null];
    }

    /** @param array<string,mixed> $configuration */
    private function miniAccessToken(array $configuration): string
    {
        $appId = (string) $configuration['app_id'];
        $secret = (string) $configuration['app_secret'];
        $cacheKey = 'wechat-auth:mini-access-token:'.hash('sha256', $appId.'|'.$secret);

        return Cache::remember($cacheKey, now()->addSeconds(7000), function () use ($configuration, $appId, $secret): string {
            try {
                $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                    ->get('https://api.weixin.qq.com/cgi-bin/token', [
                        'grant_type' => 'client_credential', 'appid' => $appId, 'secret' => $secret,
                    ]);
            } catch (ConnectionException) {
                throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信接口凭证获取失败，请稍后重试。', 503);
            }
            $payload = $response->json();
            $token = is_array($payload) ? ($payload['access_token'] ?? null) : null;
            if ($response->serverError() || ! is_string($token) || $token === '') {
                throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信接口凭证获取失败，请检查小程序配置。', 503);
            }

            return $token;
        });
    }

    private function providerName(string $channel): string
    {
        return $channel === 'wechat_mini' ? 'mini' : 'official_account';
    }
}
