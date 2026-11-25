<?php

namespace App\Modules\Robot\Services;

use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Robot\Models\RobotForwardConfig;
use App\Modules\Task\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RobotForwardConfigService
{
    public const CONTENT_TYPES = ['community_post', 'task'];

    public const POST_STATUSES = ['published', 'pending_review', 'rejected', 'hidden', 'deleted'];

    public const TASK_STATUSES = ['published', 'draft', 'pending_review', 'rejected', 'offline', 'archived'];

    public function __construct(private readonly RobotForwardContentService $content) {}

    /** @param array<string, mixed> $data */
    public function normalize(array $data): array
    {
        $filter = $data['filter_rules'] ?? [];
        $template = $data['template_config'] ?? [];
        $contentType = $this->contentType($filter);
        $categoryMap = $contentType === 'task'
            ? RobotForwardContentService::TASK_CATEGORIES
            : CommunityPost::CATEGORIES;
        $statuses = $contentType === 'task' ? self::TASK_STATUSES : self::POST_STATUSES;
        $statusField = $contentType === 'task' ? 'task_statuses' : 'post_statuses';
        $targets = array_map(function (array $target): array {
            $groups = collect($target['groups'] ?? [])
                ->map(fn (array $group): array => [
                    'group_id' => trim((string) $group['group_id']),
                    'group_name' => $this->nullableString($group['group_name'] ?? null),
                ])
                ->unique('group_id')
                ->values()
                ->all();

            return [
                'target_id' => $this->nullableString($target['target_id'] ?? null) ?? strtolower((string) Str::ulid()),
                'device_id' => trim((string) $target['device_id']),
                'device_name' => $this->nullableString($target['device_name'] ?? null),
                'wxid' => trim((string) $target['wxid']),
                'account_name' => $this->nullableString($target['account_name'] ?? null),
                'groups' => $groups,
            ];
        }, $data['robot_targets'] ?? []);
        $categories = collect($filter['categories'] ?? [])
            ->map(function (array $category) use ($categoryMap): array {
                $code = trim((string) $category['code']);

                return ['code' => $code, 'name' => $categoryMap[$code] ?? $code];
            })
            ->unique('code')
            ->values()
            ->all();

        return [
            'name' => trim((string) $data['name']),
            'description' => $this->nullableString($data['description'] ?? null),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'filter_rules' => [
                'content_type' => $contentType,
                'platforms' => $this->stringList($filter['platforms'] ?? []),
                'campus_id' => $this->nullableString($filter['campus_id'] ?? null),
                'campus_name' => $this->nullableString($filter['campus_name'] ?? null),
                'categories' => $categories,
                'post_statuses' => $contentType === 'task' ? [] : $this->normalizeStatuses($filter['post_statuses'] ?? ['published'], $statuses),
                'task_statuses' => $contentType === 'task' ? $this->normalizeStatuses($filter['task_statuses'] ?? ['published'], $statuses) : [],
                'aggregate_interval' => (int) $filter['aggregate_interval'],
                'min_messages' => (int) $filter['min_messages'],
                'max_messages' => (int) $filter['max_messages'],
                'quiet_start' => $this->nullableString($filter['quiet_start'] ?? null),
                'quiet_end' => $this->nullableString($filter['quiet_end'] ?? null),
                'active_weekdays' => collect($filter['active_weekdays'] ?? [])->map(fn (mixed $day): int => (int) $day)->unique()->sort()->values()->all(),
            ],
            'template_config' => [
                'template' => trim((string) $template['template']),
                'footer' => $this->nullableString($template['footer'] ?? null),
                'keywords' => $this->stringList($template['keywords'] ?? []),
                'content_max_length' => (int) ($template['content_max_length'] ?? 0),
            ],
            'robot_targets' => $targets,
        ];
    }

    /** @return array<string, mixed> */
    public function present(RobotForwardConfig $config): array
    {
        $stats = $config->messages()
            ->where('trigger_type', 'CONFIG_TEST')
            ->selectRaw('COUNT(*) AS test_count, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS success_count, MAX(submitted_at) AS last_tested_at', ['SUCCESS'])
            ->first();
        $lastTestedAt = $stats?->getAttribute('last_tested_at');
        $filter = $config->filter_rules ?? [];
        $contentType = $this->contentType($filter);
        $categoryMap = $contentType === 'task'
            ? RobotForwardContentService::TASK_CATEGORIES
            : CommunityPost::CATEGORIES;
        $filter['content_type'] = $contentType;
        $filter['campus_id'] ??= null;
        $filter['campus_name'] ??= null;
        if ($contentType === 'task') {
            $filter['task_statuses'] = $this->normalizeStatuses($filter['task_statuses'] ?? ['published'], self::TASK_STATUSES);
            if ($filter['task_statuses'] === []) {
                $filter['task_statuses'] = ['published'];
            }
            $filter['post_statuses'] = [];
        } else {
            $filter['post_statuses'] = $this->normalizeStatuses($filter['post_statuses'] ?? ['published'], self::POST_STATUSES);
            if ($filter['post_statuses'] === []) {
                $filter['post_statuses'] = ['published'];
            }
            $filter['task_statuses'] = [];
        }
        $filter['categories'] = collect($filter['categories'] ?? [])
            ->map(function (mixed $category) use ($categoryMap): array {
                $code = is_array($category) ? trim((string) ($category['code'] ?? '')) : trim((string) $category);

                return ['code' => $code, 'name' => $categoryMap[$code] ?? $code];
            })
            ->filter(fn (array $category): bool => $category['code'] !== '')
            ->values()
            ->all();

        return [
            'id' => $config->public_id,
            'name' => $config->name,
            'description' => $config->description,
            'enabled' => $config->enabled,
            'filter_rules' => $filter,
            'template_config' => $config->template_config,
            'robot_targets' => $config->robot_targets,
            'last_forwarded_at' => $config->last_forwarded_at?->toIso8601String(),
            'stats' => [
                'test_count' => (int) ($stats?->getAttribute('test_count') ?? 0),
                'success_count' => (int) ($stats?->getAttribute('success_count') ?? 0),
                'last_tested_at' => $lastTestedAt ? Carbon::parse($lastTestedAt)->toIso8601String() : null,
            ],
            'created_at' => $config->created_at?->toIso8601String(),
            'updated_at' => $config->updated_at?->toIso8601String(),
        ];
    }

    public function renderTestMessage(RobotForwardConfig $config, int $count = 1): string
    {
        return $this->renderPosts($config, $this->samplePosts($config->filter_rules ?? [], max(1, $count)));
    }

    /** 将一批帖子/任务按模板渲染为一条消息，结尾文案只在最后追加一次。 */
    public function renderPosts(RobotForwardConfig $config, Collection $posts): string
    {
        $templateConfig = $config->template_config ?? [];
        $limit = (int) ($templateConfig['content_max_length'] ?? 0);
        $template = (string) ($templateConfig['template'] ?? '');
        $contentType = $this->contentType($config->filter_rules ?? []);
        if ($posts->isEmpty()) {
            return $this->content->render($template, $this->content->sampleVariables($limit, $contentType));
        }
        $total = $posts->count();

        $message = $posts->values()
            ->map(function (mixed $item, int $index) use ($limit, $template, $total): string {
                $variables = $item instanceof Task
                    ? $this->content->taskVariables($item, $index + 1, $total, $limit)
                    : $this->content->variables($item, $index + 1, $total, $limit);

                return $this->content->render($template, $variables);
            })
            ->implode("\n\n");
        $footer = trim((string) ($templateConfig['footer'] ?? ''));

        return $footer === '' ? $message : $message."\n\n".$footer;
    }

    /** 按筛选条件构造查询（社区帖子或任务），供测试预览与自动转发共用。 */
    public function matchingPostsQuery(array $filter): Builder
    {
        return $this->contentType($filter) === 'task'
            ? $this->taskQuery($filter)
            : $this->communityPostQuery($filter);
    }

    private function communityPostQuery(array $filter): Builder
    {
        $statuses = $this->normalizeStatuses($filter['post_statuses'] ?? ['published'], self::POST_STATUSES);
        if ($statuses === []) {
            $statuses = ['published'];
        }
        $query = CommunityPost::query()->whereIn('status', $statuses);
        if ($statuses === ['published']) {
            $query->whereNotNull('published_at');
        }
        $campusId = $filter['campus_id'] ?? null;
        if (is_string($campusId) && $campusId !== '') {
            $campus = Campus::query()->where('public_id', $campusId)->first();
            if ($campus === null) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('campus_id', $campus->id);
        }
        $codes = collect($filter['categories'] ?? [])
            ->map(fn (array $category): string => (string) ($category['code'] ?? ''))
            ->filter(fn (string $code): bool => $code !== '')
            ->values()
            ->all();
        if ($codes !== []) {
            $query->whereIn('category_code', $codes);
        }

        return $query;
    }

    private function taskQuery(array $filter): Builder
    {
        $statuses = $this->normalizeStatuses($filter['task_statuses'] ?? ['published'], self::TASK_STATUSES);
        if ($statuses === []) {
            $statuses = ['published'];
        }
        // 任务的 title/description/category 存放在当前发布版本的 task_versions.content 中，
        // 关联当前发布版本后才能按任务分类过滤。
        $query = Task::query()
            ->select('tasks.*')
            ->join('task_versions', function ($join): void {
                $join->on('task_versions.task_id', '=', 'tasks.id')
                    ->whereColumn('task_versions.version_no', '=', 'tasks.published_version_no');
            })
            ->whereIn('tasks.status', $statuses);
        if (in_array('published', $statuses, true)) {
            $query->whereNotNull('tasks.published_at');
        }
        $campusId = $filter['campus_id'] ?? null;
        if (is_string($campusId) && $campusId !== '') {
            $campus = Campus::query()->where('public_id', $campusId)->first();
            if ($campus === null) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('tasks.campus_id', $campus->id);
        }
        $codes = collect($filter['categories'] ?? [])
            ->map(fn (array $category): string => (string) ($category['code'] ?? ''))
            ->filter(fn (string $code): bool => $code !== '')
            ->values()
            ->all();
        if ($codes !== []) {
            $query->whereIn(
                DB::raw("task_versions.content->>'category'"),
                $codes,
            );
        }

        return $query;
    }

    /** 取符合筛选条件的已发布内容（帖子/任务），让测试消息展示真实字段与链接。 */
    private function samplePosts(array $filter, int $limit): Collection
    {
        return $this->matchingPostsQuery($filter)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function contentType(array $filter): string
    {
        $type = trim((string) ($filter['content_type'] ?? 'community_post'));

        return in_array($type, self::CONTENT_TYPES, true) ? $type : 'community_post';
    }

    /** @param array<int, mixed> $values @param array<int, string> $allowed @return array<int, string> */
    private function normalizeStatuses(array $values, array $allowed): array
    {
        return collect($values)
            ->map(fn (mixed $status): string => trim((string) $status))
            ->filter(fn (string $status): bool => in_array($status, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function stringList(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
