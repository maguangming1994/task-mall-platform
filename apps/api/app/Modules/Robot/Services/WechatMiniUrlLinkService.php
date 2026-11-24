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
 * 未配置小程序凭据或微信接口失败时回退为该帖子的 H5 链接，
 * 保证转发内容始终带有可定位到帖子的链接。
 */
class WechatMiniUrlLinkService
{
    private const POST_PATH = 'pages/community-detail/index';

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

        try {
            $link = $this->request($configuration, ['id' => (string) $post->public_id], false);
        } catch (Throwable) {
            return $fallback;
        }
        if ($link === null) {
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
        } catch (ConnectionException) {
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
