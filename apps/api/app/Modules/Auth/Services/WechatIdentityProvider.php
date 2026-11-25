<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\AuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WechatIdentityProvider
{
    /**
     * 微信服务端配置类错误码：问题在平台侧（AppID/AppSecret/IP 白名单），
     * 而非用户的临时登录凭证。必须与 40029 区分响应，避免以「凭证无效或已过期」
     * 的客户端文案掩盖服务端配置故障（微信审核曾因此驳回登录体验）。
     * - 40013 invalid appid：AppID 无效或与小程序不匹配
     * - 40125 invalid appsecret：AppSecret 不正确或已在公众平台重置
     * - 40164 invalid ip：服务器出口 IP 未加入小程序后台 IP 白名单
     *
     * @var list<int>
     */
    private const CONFIGURATION_ERROR_CODES = [40013, 40125, 40164];

    public function __construct(private readonly WechatProviderSettingsService $settings) {}

    /** @return array{openid:string,unionid:?string,app_id_hash:string,profile:?array<string,mixed>} */
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

        return [
            ...$identity,
            'app_id_hash' => hash('sha256', (string) $configuration['app_id']),
            'profile' => $identity['profile'] ?? null,
        ];
    }

    public function available(string $channel): bool
    {
        $configuration = $this->settings->effective($this->providerName($channel));
        if (($configuration['login_enabled'] ?? true) !== true) {
            return false;
        }
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
            throw new AuthException(
                'WECHAT_PHONE_PROVIDER_NOT_CONFIGURED',
                '微信手机号授权暂时不可用，请使用短信验证码完成绑定，或稍后重试。',
                503,
            );
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

        return $this->validatedPayload($response->json(), 'mini');
    }

    /** @param array<string,mixed> $configuration
     * @return array{openid:string,unionid:?string,profile:?array<string,mixed>}
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

        $payload = $response->json();
        $identity = $this->validatedPayload($payload, 'official_account');
        $accessToken = is_array($payload) && is_string($payload['access_token'] ?? null)
            ? $payload['access_token']
            : null;

        return [
            ...$identity,
            'profile' => $accessToken === null
                ? null
                : $this->fetchOfficialAccountProfile($accessToken, $identity['openid'], $configuration),
        ];
    }

    /**
     * 拉取公众号用户昵称/头像。失败只降级为「仅 openid 登录」，不阻断登录，
     * 避免因 userinfo 接口异常导致用户完全无法进入 H5。
     *
     * @param  array<string,mixed>  $configuration
     * @return array<string,mixed>|null
     */
    private function fetchOfficialAccountProfile(string $accessToken, string $openid, array $configuration): ?array
    {
        try {
            $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                ->get('https://api.weixin.qq.com/sns/userinfo', [
                    'access_token' => $accessToken,
                    'openid' => $openid,
                    'lang' => 'zh_CN',
                ]);
        } catch (ConnectionException) {
            Log::warning('公众号用户资料获取失败：连接异常，已降级为仅 openid 登录。', [
                'openid' => $this->maskValue($openid),
            ]);

            return null;
        }

        $payload = $response->json();
        if ($response->serverError() || ! is_array($payload) || ! is_string($payload['openid'] ?? null)) {
            Log::warning('公众号用户资料获取失败，已降级为仅 openid 登录。', [
                'openid' => $this->maskValue($openid),
                'wechat_errcode' => is_array($payload) ? ($payload['errcode'] ?? null) : null,
                'wechat_errmsg' => is_array($payload) ? ($payload['errmsg'] ?? null) : null,
            ]);

            return null;
        }

        return [
            'nickname' => is_string($payload['nickname'] ?? null) ? $payload['nickname'] : null,
            'headimgurl' => is_string($payload['headimgurl'] ?? null) ? $payload['headimgurl'] : null,
            'sex' => is_numeric($payload['sex'] ?? null) ? (int) $payload['sex'] : null,
            'city' => is_string($payload['city'] ?? null) ? $payload['city'] : null,
            'province' => is_string($payload['province'] ?? null) ? $payload['province'] : null,
            'country' => is_string($payload['country'] ?? null) ? $payload['country'] : null,
        ];
    }

    /** 日志中不落原始 openid，保留前 4 后 4 位。 */
    private function maskValue(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 8) {
            return str_repeat('*', max($length, 1));
        }

        return mb_substr($value, 0, 4).'****'.mb_substr($value, -4);
    }

    /** @param array<string,mixed>|null $payload
     * @return array{openid:string,unionid:?string}
     */
    private function validatedPayload(?array $payload, string $channel): array
    {
        if (is_array($payload) && is_string($payload['openid'] ?? null)) {
            return ['openid' => $payload['openid'], 'unionid' => is_string($payload['unionid'] ?? null) ? $payload['unionid'] : null];
        }

        // 微信拒绝换票时不返回 openid，仅给 errcode/errmsg（如 40029 invalid code、
        // 40013 invalid appid、40125 invalid appsecret）。原始错误码落日志，
        // 便于在无法复现审核环境时快速区分「用户凭证问题」与「平台配置问题」。
        $errcode = is_array($payload) ? $payload['errcode'] ?? null : null;
        $errcode = is_numeric($errcode) ? (int) $errcode : $errcode;
        $errmsg = is_array($payload) ? (string) ($payload['errmsg'] ?? '') : '';
        $context = ['channel' => $channel, 'wechat_errcode' => $errcode, 'wechat_errmsg' => $errmsg];

        if (in_array($errcode, self::CONFIGURATION_ERROR_CODES, true)) {
            Log::error('微信登录换票失败：疑似平台 AppID/AppSecret/IP 白名单配置异常。', $context);

            throw new AuthException('WECHAT_PROVIDER_UNAVAILABLE', '微信登录服务暂时不可用，请稍后重试或使用手机号登录。', 503);
        }

        Log::warning('微信登录换票失败：临时登录凭证被微信拒绝。', $context);

        throw new AuthException('WECHAT_AUTH_FAILED', '微信临时登录凭证无效或已过期。', 422);
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
