<?php

namespace App\Modules\Auth\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WechatAccessTokenService
{
    public function __construct(private readonly WechatProviderSettingsService $settings) {}

    public function token(string $provider, bool $refresh = false): string
    {
        $configuration = $this->settings->effective($provider);
        if ($configuration['driver'] !== 'wechat' || blank($configuration['app_id']) || blank($configuration['app_secret'])) {
            throw new RuntimeException('微信应用凭据尚未完成配置。');
        }
        $key = $this->cacheKey($provider, $configuration);
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addSeconds(7000), function () use ($configuration): string {
            try {
                $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                    ->get('https://api.weixin.qq.com/cgi-bin/token', [
                        'grant_type' => 'client_credential',
                        'appid' => $configuration['app_id'],
                        'secret' => $configuration['app_secret'],
                    ]);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('微信接口凭证获取失败。', 0, $exception);
            }
            $payload = $response->json();
            $token = is_array($payload) ? ($payload['access_token'] ?? null) : null;
            if ($response->failed() || ! is_string($token) || $token === '') {
                throw new RuntimeException('微信接口拒绝了当前应用凭据。');
            }

            return $token;
        });
    }

    /** @param array<string,mixed> $configuration */
    private function cacheKey(string $provider, array $configuration): string
    {
        return 'wechat-access-token:'.$provider.':'.hash('sha256', $configuration['app_id'].'|'.$configuration['app_secret']);
    }
}
