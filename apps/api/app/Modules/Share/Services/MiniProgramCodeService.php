<?php

namespace App\Modules\Share\Services;

use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 生成微信小程序码（getwxacode）。
 *
 * 海报二维码优先使用小程序码：微信扫描后直接进入对应小程序页面。
 * 小程序未配置、未发布或微信接口异常时返回 null，由调用方回退到 H5 链接二维码。
 */
class MiniProgramCodeService
{
    /** 小程序码有效期缓存 29 天；小程序码本身长期有效。 */
    private const CACHE_DAYS = 29;

    /** 不可用状态只缓存 5 分钟，避免小程序刚发布后长时间仍走 H5。 */
    private const UNAVAILABLE_MINUTES = 5;

    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    public function available(): bool
    {
        $configuration = $this->settings->effective('mini');

        return $configuration['driver'] === 'wechat'
            && filled($configuration['app_id'])
            && filled($configuration['app_secret']);
    }

    /**
     * @param  string  $path  小程序页面路径，可带查询参数，最长 128 字节
     * @return string|null PNG 二进制内容；小程序侧不可用时返回 null
     */
    public function png(string $path, int $width = 430): ?string
    {
        if (! $this->available()) {
            return null;
        }
        $configuration = $this->settings->effective('mini');
        $cacheKey = 'share:minicode:'.hash('sha256', $configuration['app_id'].'|'.$path.'|'.$width);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (Cache::has($cacheKey.':unavailable')) {
            return null;
        }

        $image = $this->request($configuration, $path, $width, false);
        if ($image === null) {
            Cache::put($cacheKey.':unavailable', true, now()->addMinutes(self::UNAVAILABLE_MINUTES));

            return null;
        }
        Cache::put($cacheKey, $image, now()->addDays(self::CACHE_DAYS));

        return $image;
    }

    /**
     * @param  array{timeout_seconds:int}  $configuration
     */
    private function request(array $configuration, string $path, int $width, bool $refreshed): ?string
    {
        try {
            $response = Http::timeout(max(5, $configuration['timeout_seconds']))
                ->withQueryParameters(['access_token' => $this->tokens->token('mini', $refreshed)])
                ->post('https://api.weixin.qq.com/wxa/getwxacode', [
                    'path' => $path,
                    'width' => $width,
                    'auto_color' => true,
                ]);
        } catch (Throwable) {
            return null;
        }

        $contentType = (string) $response->header('Content-Type');
        if (! $response->failed() && str_starts_with($contentType, 'image/')) {
            $body = $response->body();

            return $body !== '' ? $body : null;
        }

        $payload = json_decode($response->body(), true);
        $code = is_array($payload) ? (int) ($payload['errcode'] ?? 0) : 0;
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->request($configuration, $path, $width, true);
        }

        return null;
    }
}
