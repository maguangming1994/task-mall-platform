<?php

namespace App\Modules\Robot\Services;

use App\Modules\Auth\Services\WechatAccessTokenService;
use App\Modules\Auth\Services\WechatProviderSettingsService;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ShortLink;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskVersion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 为社区帖子 / 任务生成微信小程序链接。
 *
 * 支持两种形式（由转发配置的 link_format 决定）：
 * - url_link：微信 URL Link（https://wxaurl.cn/...），短信/网页/微信内外全场景可点；
 * - short_link：微信 Short Link，聊天里落地为 mp://... 形式，微信内点击直跳小程序页面。
 *
 * 只有小程序未配置、未发布或微信接口明确不可用时，才回退为对应内容的 H5 链接。
 */
class WechatMiniUrlLinkService
{
    private const POST_PATH = 'pages/community-detail/index';

    private const TASK_PATH = 'pages/task-detail/index';

    /** 小程序短链不可用状态缓存 5 分钟，避免每次转发都重复请求微信接口。 */
    private const UNAVAILABLE_MINUTES = 5;

    public function __construct(
        private readonly WechatProviderSettingsService $settings,
        private readonly WechatAccessTokenService $tokens,
    ) {}

    public function forCommunityPost(CommunityPost $post, string $format = 'url_link'): string
    {
        return $this->generateUrlLink(
            $post->public_id.'|'.($post->published_at?->getTimestamp() ?? 0),
            self::POST_PATH,
            ['id' => (string) $post->public_id],
            $this->h5Url($post),
            $format,
            (string) $post->title,
        );
    }

    public function forTask(Task $task, string $format = 'url_link'): string
    {
        $pageTitle = '校园任务';
        if ($format === 'short_link') {
            $version = TaskVersion::query()
                ->where('task_id', $task->id)
                ->where('version_no', $task->published_version_no)
                ->first();
            $snapshot = is_array($version?->content) ? $version->content : [];
            $pageTitle = (string) ($snapshot['title'] ?? '校园任务');
        }

        return $this->generateUrlLink(
            $task->public_id.'|'.($task->published_at?->getTimestamp() ?? 0),
            self::TASK_PATH,
            ['id' => (string) $task->public_id],
            $this->taskH5Url($task),
            $format,
            $pageTitle,
        );
    }

    public function h5Url(CommunityPost $post): string
    {
        return $this->shortUrl($post);
    }

    public function taskH5Url(Task $task): string
    {
        $base = rtrim((string) config('app.user_h5_url'), '/');
        if ($base === '') {
            $base = rtrim((string) config('app.url'), '/');
        }

        return $base.'/#/'.self::TASK_PATH.'?id='.rawurlencode((string) $task->public_id);
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
     * 生成并缓存微信小程序链接（URL Link 或 Short Link）；小程序不可用时回退 H5。
     *
     * @param  array<string, string>  $query
     */
    private function generateUrlLink(string $cacheSeed, string $path, array $query, string $fallback, string $format = 'url_link', string $pageTitle = ''): string
    {
        $configuration = $this->settings->effective('mini');
        if ($configuration['driver'] !== 'wechat' || blank($configuration['app_id']) || blank($configuration['app_secret'])) {
            return $fallback;
        }

        $format = $format === 'short_link' ? 'short_link' : 'url_link';
        $cacheKey = 'wechat:mini:'.$format.':'.hash('sha256', $configuration['app_id'].'|'.$path.'|'.$cacheSeed);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (Cache::has($cacheKey.':unavailable')) {
            return $fallback;
        }

        try {
            $link = $format === 'short_link'
                ? $this->requestShortLink($configuration, $path, $query, $pageTitle, false)
                : $this->request($configuration, $path, $query, false);
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

    /**
     * @param  array{timeout_seconds:int}  $configuration
     * @param  array<string, string>  $query
     */
    private function request(array $configuration, string $path, array $query, bool $refreshed): ?string
    {
        $response = null;
        // 跨境网络偶发超时：重试一次，避免小程序仍在线却因瞬时抖动降级为 H5 短链
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                    ->withQueryParameters(['access_token' => $this->tokens->token('mini', $refreshed)])
                    ->post('https://api.weixin.qq.com/wxa/generate_urllink', [
                        'path' => $path,
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
            return $this->request($configuration, $path, $query, true);
        }

        return null;
    }

    /**
     * 生成微信 Short Link 并转成 mp:// 形式。
     *
     * @param  array{timeout_seconds:int}  $configuration
     * @param  array<string, string>  $query
     */
    private function requestShortLink(array $configuration, string $path, array $query, string $pageTitle, bool $refreshed): ?string
    {
        $fullPath = $query === [] ? $path : $path.'?'.http_build_query($query);
        $response = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::acceptJson()->timeout($configuration['timeout_seconds'])
                    ->withQueryParameters(['access_token' => $this->tokens->token('mini', $refreshed)])
                    ->post('https://api.weixin.qq.com/wxa/generateshortlink', [
                        'path' => $fullPath,
                        'page_title' => $pageTitle !== '' ? $pageTitle : '校园内容',
                        'is_permanent' => false,
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
            $link = $payload['link'] ?? null;

            return is_string($link) ? $this->shortLinkToMp($link) : null;
        }
        if (! $refreshed && in_array($code, [40001, 40014, 42001], true)) {
            return $this->requestShortLink($configuration, $path, $query, $pageTitle, true);
        }

        return null;
    }

    /** 把微信 Short Link（#小程序://名称/页面/随机串）转换为聊天里可点的 mp:// 形式。 */
    private function shortLinkToMp(string $link): ?string
    {
        $link = trim($link);
        if ($link === '') {
            return null;
        }
        if (str_starts_with($link, 'mp://')) {
            return $link;
        }
        $segments = explode('/', rtrim($link, '/'));
        $ticket = end($segments);

        return is_string($ticket) && $ticket !== '' ? 'mp://'.$ticket : null;
    }
}
