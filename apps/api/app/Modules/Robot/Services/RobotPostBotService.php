<?php

namespace App\Modules\Robot\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Robot\Models\RobotPostBot;
use App\Modules\Robot\Models\RobotPostTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RobotPostBotService
{
    public function __construct(private readonly CommunityContentService $content) {}

    /**
     * 扫描启用中的发帖机器人，满足频率与时段时按内容来源发布一条帖子。
     *
     * @return array{posted: int, checked: int}
     */
    public function dispatchDue(?string $publicId = null, int $limit = 20): array
    {
        $query = RobotPostBot::query()->where('enabled', true)->orderBy('id');
        if (is_string($publicId) && $publicId !== '') {
            $query->where('public_id', $publicId);
        }

        $posted = 0;
        $checked = 0;
        foreach ($query->limit(max(1, $limit))->get() as $bot) {
            $checked++;
            if (! $this->due($bot)) {
                continue;
            }

            $postedToday = $bot->posted_date?->toDateString() === now()->toDateString() ? (int) $bot->posted_today : 0;
            $remaining = max(0, (int) $bot->posts_per_day - $postedToday);
            $batch = min(max(1, (int) $bot->posts_per_run), $remaining);
            for ($sequence = 1; $sequence <= $batch; $sequence++) {
                if ($this->publishOne($bot, null, $sequence) === null) {
                    break;
                }
                $posted++;
            }
        }

        return ['posted' => $posted, 'checked' => $checked];
    }

    /** 立即发布一条（后台手动触发），不受每日次数与时段限制。 */
    public function publishNow(RobotPostBot $bot, AdminUser $admin): CommunityPost
    {
        $post = $this->publishOne($bot, $admin);
        if ($post === null) {
            throw new \RuntimeException($bot->fresh()->last_error ?: '发帖失败，请检查机器人配置。');
        }

        return $post;
    }

    private function publishOne(RobotPostBot $bot, ?AdminUser $admin = null, ?int $sequence = null): ?CommunityPost
    {
        $admin ??= $this->admin($bot);
        if ($admin === null) {
            $bot->forceFill(['last_error' => '缺少可用的管理员账号，无法以运营身份发布。'])->save();

            return null;
        }

        $content = $this->resolveContent($bot);
        if ($content === null) {
            return null;
        }

        try {
            $post = $this->content->createPostAsAdmin($admin, $this->author($bot), [
                'title' => $content['title'],
                'content' => $content['content'],
                'category_code' => $bot->category_code,
                'admin_note' => '发帖机器人「'.$bot->name.'」自动发布。',
                'idempotency_key' => 'post-bot:'.$bot->public_id.':'.now()->format('YmdHi').':'.($sequence ?? (string) Str::ulid()),
            ]);
        } catch (Throwable $exception) {
            $bot->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();

            return null;
        }

        // createPostAsAdmin 不写校区，机器人帖子必须落到目标学校，前台按校区展示才能命中。
        if ($post->campus_id !== $bot->campus_id) {
            $post->forceFill(['campus_id' => $bot->campus_id])->save();
        }

        $today = now()->toDateString();
        $bot->forceFill([
            'posted_today' => $bot->posted_date?->toDateString() === $today ? $bot->posted_today + 1 : 1,
            'posted_date' => $today,
            'last_posted_at' => now(),
            'last_error' => null,
        ])->save();

        return $post;
    }

    /** @return array{title: string, content: string}|null */
    private function resolveContent(RobotPostBot $bot): ?array
    {
        $content = match ($bot->content_source) {
            'pool' => $this->fromPool($bot),
            'history' => $this->fromHistory($bot),
            'ai' => $this->fromAi($bot),
            default => null,
        };

        if ($content !== null) {
            return $content;
        }
        if ($bot->last_error === null) {
            $bot->forceFill(['last_error' => '没有可用的内容来源，请检查帖子池或历史帖子条件。'])->save();
        }

        return null;
    }

    /** @return array{title: string, content: string}|null */
    private function fromPool(RobotPostBot $bot): ?array
    {
        $template = RobotPostTemplate::query()
            ->where('robot_post_bot_id', $bot->id)
            ->where('enabled', true)
            ->orderByRaw('used_at IS NULL DESC')
            ->orderBy('used_at')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
        if ($template === null) {
            return null;
        }

        $template->forceFill(['used_at' => now()])->save();

        return $this->fillVariables($bot, $template->title, $template->content);
    }

    /** @return array{title: string, content: string}|null */
    private function fromHistory(RobotPostBot $bot): ?array
    {
        $candidates = CommunityPost::query()
            ->where('campus_id', $bot->campus_id)
            ->where('category_code', $bot->category_code)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now()->subDays(3))
            ->where('like_count', '>=', (int) $bot->history_min_likes)
            ->orderByDesc('like_count')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($candidates as $post) {
            $published = CommunityPost::query()
                ->where('user_id', $this->author($bot)->id)
                ->where('title', $post->title)
                ->exists();
            if (! $published) {
                return $this->fillVariables($bot, $post->title, $post->content);
            }
        }

        return null;
    }

    /** @return array{title: string, content: string}|null */
    private function fromAi(RobotPostBot $bot): ?array
    {
        $baseUrl = rtrim((string) config('services.robot_ai.base_url'), '/');
        $key = (string) config('services.robot_ai.api_key');
        $model = (string) config('services.robot_ai.model');
        if ($baseUrl === '' || $key === '' || $model === '') {
            $bot->forceFill(['last_error' => '未配置 AI 服务（ROBOT_AI_BASE_URL / ROBOT_AI_API_KEY / ROBOT_AI_MODEL）。'])->save();

            return null;
        }

        $campus = Campus::query()->find($bot->campus_id);
        $category = CommunityPost::CATEGORIES[$bot->category_code] ?? $bot->category_code;
        $instruction = trim((string) $bot->ai_prompt) ?: '结合校园生活写一条自然、真实、有互动感的帖子，不要出现广告或联系方式。';
        $prompt = '你是校园社区运营助手，为「'.($campus?->name ?? '校园').'」生成一条「'.$category.'」类帖子。'.$instruction
            .' 只输出 JSON：{"title":"不超过 30 字的标题","content":"80-200 字正文"}';

        try {
            $response = Http::withToken($key)->timeout(30)->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.9,
            ]);
        } catch (Throwable $exception) {
            $bot->forceFill(['last_error' => 'AI 请求失败：'.mb_substr($exception->getMessage(), 0, 400)])->save();

            return null;
        }

        if (! $response->successful()) {
            $bot->forceFill(['last_error' => 'AI 返回异常：HTTP '.$response->status()])->save();

            return null;
        }

        $text = (string) data_get($response->json(), 'choices.0.message.content', '');
        if (preg_match('/\{.*\}/s', $text, $matches) !== 1) {
            $bot->forceFill(['last_error' => 'AI 输出不是有效 JSON。'])->save();

            return null;
        }
        $data = json_decode($matches[0], true);
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($title === '' || $content === '') {
            $bot->forceFill(['last_error' => 'AI 输出缺少标题或正文。'])->save();

            return null;
        }

        return ['title' => $title, 'content' => $content];
    }

    /** @return array{title: string, content: string} */
    private function fillVariables(RobotPostBot $bot, string $title, string $content): array
    {
        $campus = Campus::query()->find($bot->campus_id);
        $replace = [
            '${campus}' => (string) ($campus?->name ?? '校园'),
            '${date}' => now()->format('Y-m-d'),
            '${weekday}' => now()->locale('zh_CN')->isoFormat('dddd'),
            '${category}' => CommunityPost::CATEGORIES[$bot->category_code] ?? '',
        ];

        return ['title' => strtr($title, $replace), 'content' => strtr($content, $replace)];
    }

    private function author(RobotPostBot $bot): User
    {
        if ($bot->author_user_id !== null) {
            $author = User::query()->find($bot->author_user_id);
            if ($author !== null) {
                return $author;
            }
        }

        $campus = Campus::query()->find($bot->campus_id);
        $author = User::query()->create([
            'campus_id' => $bot->campus_id,
            'nickname' => ($campus?->name ?: '校园').'小助手',
            'status' => 'active',
            'is_bot' => true,
        ]);
        $bot->forceFill(['author_user_id' => $author->id])->save();

        return $author;
    }

    private function admin(RobotPostBot $bot): ?AdminUser
    {
        if ($bot->created_by_admin_id !== null) {
            $admin = AdminUser::query()->find($bot->created_by_admin_id);
            if ($admin !== null) {
                return $admin;
            }
        }

        return AdminUser::query()->where('status', 'active')->orderBy('id')->first();
    }

    private function due(RobotPostBot $bot): bool
    {
        if (! $this->activeToday($bot) || ! $this->inWindow($bot)) {
            return false;
        }
        $postedToday = $bot->posted_date?->toDateString() === now()->toDateString() ? $bot->posted_today : 0;
        if ($postedToday >= max(1, (int) $bot->posts_per_day)) {
            return false;
        }
        $minutes = max(1, (int) $bot->min_gap_minutes);
        if ($bot->last_posted_at !== null
            && $bot->last_posted_at->copy()->startOfMinute()->addMinutes($minutes)->gt(now()->startOfMinute())) {
            return false;
        }

        return true;
    }

    private function activeToday(RobotPostBot $bot): bool
    {
        $weekdays = collect($bot->active_weekdays ?? [])->map(fn (mixed $day): int => (int) $day)->all();
        if ($weekdays === []) {
            return true;
        }

        return in_array(now()->dayOfWeekIso, $weekdays, true);
    }

    private function inWindow(RobotPostBot $bot): bool
    {
        $start = $this->minutes($bot->active_start);
        $end = $this->minutes($bot->active_end);
        if ($start === null || $end === null || $start === $end) {
            return true;
        }
        $now = now()->hour * 60 + now()->minute;

        return $start < $end ? $now >= $start && $now < $end : $now >= $start || $now < $end;
    }

    private function minutes(?string $value): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    /** 在下一次发布前刷新今日计数展示，避免跨天残留。 */
    public function present(RobotPostBot $bot): array
    {
        $today = now()->toDateString();
        $postedToday = $bot->posted_date?->toDateString() === $today ? $bot->posted_today : 0;
        $campus = Campus::query()->find($bot->campus_id);
        $templates = $bot->templates();

        return [
            'id' => $bot->public_id,
            'name' => $bot->name,
            'enabled' => $bot->enabled,
            'campus_id' => $campus?->public_id,
            'campus_name' => $campus?->name,
            'category_code' => $bot->category_code,
            'category_name' => CommunityPost::CATEGORIES[$bot->category_code] ?? $bot->category_code,
            'content_source' => $bot->content_source,
            'posts_per_day' => $bot->posts_per_day,
            'posts_per_run' => max(1, (int) $bot->posts_per_run),
            'posted_today' => $postedToday,
            'active_weekdays' => $bot->active_weekdays ?? [],
            'active_start' => $bot->active_start,
            'active_end' => $bot->active_end,
            'min_gap_minutes' => $bot->min_gap_minutes,
            'history_min_likes' => $bot->history_min_likes,
            'ai_prompt' => $bot->ai_prompt,
            'last_posted_at' => $bot->last_posted_at?->toIso8601String(),
            'last_error' => $bot->last_error,
            'templates_total' => (clone $templates)->count(),
            'templates_available' => (clone $templates)->where('enabled', true)->count(),
            'created_at' => $bot->created_at?->toIso8601String(),
            'updated_at' => $bot->updated_at?->toIso8601String(),
        ];
    }
}
