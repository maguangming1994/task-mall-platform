<?php

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 公众号 JS-SDK 签名。
 *
 * access_token / jsapi_ticket 按 AppID+AppSecret 的哈希缓存，
 * 因此后台更换公众号凭据后缓存键自然失效，无需手动清理。
 */
class WechatJssdkService
{
    private const TOKEN_TTL_SECONDS = 7000;

    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(private readonly WechatProviderSettingsService $settings)
    {
    }

    /** @return array{appId:string,timestamp:int,nonceStr:string,signature:string} */
    public function signature(string $url): array
    {
        $config = $this->settings->effective('official_account');
        $appId = trim((string) $config['app_id']);
        $appSecret = (string) $config['app_secret'];

        if ($config['driver'] !== 'wechat' || $appId === '' || $appSecret === '') {
            throw new RuntimeException('公众号 JSSDK 尚未配置，无法生成分享签名。');
        }

        $ticket = $this->jsapiTicket($appId, $appSecret);
        $nonceStr = bin2hex(random_bytes(8));
        $timestamp = time();
        $signature = sha1(sprintf(
            'jsapi_ticket=%s&noncestr=%s&timestamp=%d&url=%s',
            $ticket,
            $nonceStr,
            $timestamp,
            $url,
        ));

        return [
            'appId' => $appId,
            'timestamp' => $timestamp,
            'nonceStr' => $nonceStr,
            'signature' => $signature,
        ];
    }

    private function jsapiTicket(string $appId, string $appSecret): string
    {
        $key = 'wechat:official_account:jsapi_ticket:'.sha1($appId.'|'.$appSecret);

        return (string) Cache::remember($key, self::TOKEN_TTL_SECONDS, function () use ($appId, $appSecret): string {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get('https://api.weixin.qq.com/cgi-bin/ticket/getticket', [
                    'access_token' => $this->accessToken($appId, $appSecret),
                    'type' => 'jsapi',
                ]);
            $ticket = (string) ($response->json('ticket') ?? '');

            if (! $response->ok() || (int) ($response->json('errcode') ?? 0) !== 0 || $ticket === '') {
                throw new RuntimeException('获取公众号 jsapi_ticket 失败，请检查公众号凭据与 IP 白名单。');
            }

            return $ticket;
        });
    }

    private function accessToken(string $appId, string $appSecret): string
    {
        $key = 'wechat:official_account:access_token:'.sha1($appId.'|'.$appSecret);

        return (string) Cache::remember($key, self::TOKEN_TTL_SECONDS, function () use ($appId, $appSecret): string {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get('https://api.weixin.qq.com/cgi-bin/token', [
                    'grant_type' => 'client_credential',
                    'appid' => $appId,
                    'secret' => $appSecret,
                ]);
            $token = (string) ($response->json('access_token') ?? '');

            if (! $response->ok() || $token === '') {
                throw new RuntimeException('获取公众号 access_token 失败，请检查公众号凭据与 IP 白名单。');
            }

            return $token;
        });
    }
}
