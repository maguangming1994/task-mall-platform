<?php

namespace App\Modules\Robot\Services;

use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ShortLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 为社区帖子生成微信小程序 URL Link（mp:// 开头）。
 *
 * 转发内容优先使用小程序短链；只有小程序未配置、未发布或微信接口明确不可用时，
 * 才回退为该帖子的 H5 短链，保证转发内容始终带有可定位到帖子的链接。
 */
class WechatMiniUrlLinkService
{
    private const POST_PATH = 'pages/community-detail/index';

    /** 小程序短链不可用状态缓存 5 分钟，避免每次转发都重复请求微信接口。 */
    private const UNAVAILABLE_MINUTES = 5;

    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    public function forCommunityPost(CommunityPost $post): string
    {
        $fallback = $this->h5Url($post);
        $configuration = $this->settings->effective('mini');
        if ($configuration['driver'] !== 'wechat' || blank($configuration['app_id']) || blank($configuration['app_secret'])) {
            return $fallback;
        }

        $cacheKey = 'wechat:mini:urllink:'.hash('sha256', implode('|', [
            $configuration['app_id'],
            (string) $post->public_id,
            (string) ($post->published_at?->getTimestamp() ?? 0),
        ]));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (Cache::has($cacheKey.':unavailable')) {
            return $fallback;
        }

        try {
            $link = $this->request($configuration, ['id' => (string) $post->public_id], false);
        } catch (Throwable) {
            $link = null;
        }
        if ($link === null) {
            Cache::put($cacheKey.':unavailable', true, now()->addMinutes(self::UNAVAILABLE_MINUTES));

            return $fallback;
        }
        Cache::put($cacheKey, $link, now()->addDays(29));

        return $link;
    }

    public function h5Url(CommunityPost $post): string
    {
        return $this->shortUrl($post);
    }

    /**
     * 为帖子生成短链，格式：{api_base}/s/{6位随机码}。
     * 走根路径，不暴露 /api/v1 前缀；随机码由 short_links 表生成，
     * 不暴露帖子自增 ID。
     */
    public function shortUrl(CommunityPost $post): string
    {
        $link = ShortLink::ensureFor('community_post', (int) $post->id);
        $apiBase = rtrim((string) config('app.url'), '/');

        return $apiBase.'/s/'.$link->code;
    }

    /**
     * @param  array{timeout_seconds:int}  $configuration
     * @param  array<string, string>  $query
     */
    private function request(array $configuration, array $query, bool $refreshed): ?string
    {
        $response = null;
        // 跨境网络偶发超时：重试一次，避免小程序仍在线却因瞬时抖动降级为 H5 短链
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                    ->withQueryParameters(['access_token' => $this->tokens->token('mini', $refreshed)])
                    ->post('https://api.weixin.qq.com/wxa/generate_urllink', [
                        'path' => self::POST_PATH,
                        'query' => http_build_query($query),
                        'expire_type' => 1,
                        'expire_interval' => 30,
                        'env_version' => 'release',
                    ]);
                break;
            } catch (ConnectionException) {
                if ($attempt === 2) {
                    return null;
                }
                usleep(200000);
            }
        }
        if ($response === null) {
            return null;
        }
        $payload = $response->json();
        $code = is_array($payload) ? (int) ($payload['errcode'] ?? -1) : -1;
        if ($code === 0) {
            $link = $payload['url_link'] ?? null;

            return is_string($link) && $link !== '' ? $link : null;
        }
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->request($configuration, $query, true);
        }

        return null;
    }
}
