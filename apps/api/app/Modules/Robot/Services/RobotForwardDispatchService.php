<?php

namespace App\Modules\Robot\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Robot\Models\RobotForwardConfig;
use App\Modules\Robot\Models\RobotMessageRequest;
use App\Modules\Robot\Models\RobotPostForwardLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RobotForwardDispatchService
{
    public function __construct(
        private readonly RobotForwardConfigService $configs,
        private readonly RobotMessageService $messages,
        private readonly XpRobotSettingsService $settings,
    ) {}

    /**
     * 扫描启用中的转发配置，把尚未转发的新帖子按配置聚合成一条消息发到目标群。
     *
     * @return array{dispatched: int, configs: int}
     */
    public function dispatchDue(?string $configPublicId = null, int $limit = 20): array
    {
        try {
            $this->settings->apiKey();
        } catch (Throwable) {
            return ['dispatched' => 0, 'configs' => 0];
        }

        $query = RobotForwardConfig::query()->where('enabled', true)->orderBy('id');
        if (is_string($configPublicId) && $configPublicId !== '') {
            $query->where('public_id', $configPublicId);
        }

        $dispatched = 0;
        $configs = 0;
        foreach ($query->limit(max(1, $limit))->get() as $config) {
            $dispatched += $this->dispatchConfig($config);
            $configs++;
        }

        return ['dispatched' => $dispatched, 'configs' => $configs];
    }

    private function dispatchConfig(RobotForwardConfig $config): int
    {
        $filter = $config->filter_rules ?? [];
        if (! $this->activeToday($filter) || $this->inQuietHours($filter) || ! $this->intervalElapsed($config, $filter)) {
            return 0;
        }

        $admin = $this->admin($config);
        if ($admin === null) {
            return 0;
        }

        $batch = $this->pendingPosts($config, $filter);
        if ($batch === null || $batch->count() < max(1, (int) ($filter['min_messages'] ?? 1))) {
            return 0;
        }

        $message = $this->configs->renderPosts($config, $batch);
        $sent = 0;
        foreach ($config->robot_targets ?? [] as $target) {
            foreach ($target['groups'] ?? [] as $group) {
                $key = sprintf('auto-forward:%s:%d:%s', $config->public_id, (int) $batch->last()->id, (string) $group['group_id']);
                $exists = RobotMessageRequest::query()
                    ->where('idempotency_key_hash', hash('sha256', $key))
                    ->exists();
                try {
                    $record = $this->messages->sendGroupText($admin, [
                        'device_id' => $target['device_id'],
                        'wxid' => $target['wxid'],
                        'group_id' => $group['group_id'],
                        'group_name' => $group['group_name'] ?? null,
                        'message' => $message,
                    ], $key, ['forward_config_id' => $config->id, 'trigger_type' => 'AUTO_FORWARD']);
                    if ($exists) {
                        continue;
                    }
                    $sent++;
                    [$status, $error] = $this->classify($record);
                    $this->logForward($batch, $config, $group, $status, $error, (int) $record->id, false);
                } catch (Throwable $exception) {
                    if (! $exists) {
                        $this->logForward($batch, $config, $group, 'failed', $exception->getMessage(), null, false);
                    }

                    continue;
                }
            }
        }

        $config->forceFill([
            'last_forwarded_post_id' => (int) $batch->last()->id,
            'last_forwarded_at' => now(),
            'last_dispatch_at' => now(),
        ])->save();

        return $sent;
    }

    /**
     * 手动重发单条帖子：按启用中的转发配置重新渲染并发送到全部目标群。
     *
     * @return array{status: string, groups: array<int, array<string, mixed>>}
     */
    public function forwardPost(AdminUser $admin, CommunityPost $post): array
    {
        if ($post->status !== 'published' || $post->trashed()) {
            throw ValidationException::withMessages(['post' => '仅已发布的帖子可以转发。']);
        }

        $campusPublicId = $post->campus_id !== null
            ? Campus::query()->whereKey($post->campus_id)->value('public_id')
            : null;
        $configs = RobotForwardConfig::query()->where('enabled', true)->orderBy('id')->get()
            ->filter(function (RobotForwardConfig $config) use ($campusPublicId): bool {
                $campusId = $config->filter_rules['campus_id'] ?? null;

                return ! is_string($campusId) || $campusId === '' || $campusId === $campusPublicId;
            });

        if ($configs->isEmpty()) {
            throw ValidationException::withMessages(['post' => '没有启用中的转发配置匹配该帖子，请先检查转发配置。']);
        }

        $posts = collect([$post]);
        $groups = [];
        foreach ($configs as $config) {
            $message = $this->configs->renderPosts($config, $posts);
            foreach ($config->robot_targets ?? [] as $target) {
                foreach ($target['groups'] ?? [] as $group) {
                    $entry = [
                        'group_id' => (string) $group['group_id'],
                        'group_name' => $group['group_name'] ?? null,
                        'status' => 'failed',
                        'error_message' => null,
                        'forwarded_at' => now()->toIso8601String(),
                    ];
                    $key = sprintf(
                        'manual-forward:%d:%s:%s:%s',
                        (int) $post->id,
                        $config->public_id,
                        (string) $group['group_id'],
                        (string) Str::ulid(),
                    );
                    try {
                        $record = $this->messages->sendGroupText($admin, [
                            'device_id' => $target['device_id'],
                            'wxid' => $target['wxid'],
                            'group_id' => $group['group_id'],
                            'group_name' => $group['group_name'] ?? null,
                            'message' => $message,
                        ], $key, ['forward_config_id' => $config->id, 'trigger_type' => 'MANUAL_FORWARD']);
                        [$status, $error] = $this->classify($record);
                        $entry['status'] = $status;
                        $entry['error_message'] = $error;
                        $this->logForward($posts, $config, $group, $status, $error, (int) $record->id, true);
                    } catch (Throwable $exception) {
                        $entry['error_message'] = $exception->getMessage();
                        $this->logForward($posts, $config, $group, 'failed', $exception->getMessage(), null, true);
                    }
                    $groups[] = $entry;
                }
            }
        }

        if ($groups === []) {
            throw ValidationException::withMessages(['post' => '匹配的转发配置没有配置任何目标群。']);
        }

        $status = collect($groups)->every(fn (array $group): bool => $group['status'] === 'success')
            ? 'success'
            : 'failed';

        return ['status' => $status, 'groups' => $groups];
    }

    /** 把发送结果归一为 success / failed / unknown。 @return array{0: string, 1: string|null} */
    private function classify(RobotMessageRequest $record): array
    {
        $status = strtoupper((string) $record->status);
        if (in_array($status, ['SUBMIT_FAILED', 'FAILED', 'FAIL', 'ERROR', 'CANCELED', 'SKIPPED'], true)) {
            return ['failed', $record->error_message ?: '转发消息发送失败。'];
        }
        if ($status === 'SUBMIT_UNKNOWN' || $status === '' || blank($record->cmd_id)) {
            return ['unknown', $record->error_message ?: '无法确认消息是否已投递，请人工核对。'];
        }

        return ['success', null];
    }

    /**
     * 为批次内每条帖子记录某个目标群的转发结果。
     *
     * @param  Collection<int, CommunityPost>  $posts
     * @param  array<string, mixed>  $group
     */
    private function logForward(
        Collection $posts,
        ?RobotForwardConfig $config,
        array $group,
        string $status,
        ?string $error,
        ?int $messageRequestId,
        bool $manual,
    ): void {
        $now = now();
        foreach ($posts as $post) {
            RobotPostForwardLog::query()->create([
                'post_id' => (int) $post->id,
                'forward_config_id' => $config?->id,
                'group_id' => (string) $group['group_id'],
                'group_name' => $group['group_name'] ?? null,
                'status' => $status,
                'message_request_id' => $messageRequestId,
                'error_message' => $error === null ? null : mb_substr($error, 0, 500),
                'manual' => $manual,
                'forwarded_at' => $now,
            ]);
        }
    }

    /** 取游标之后、符合筛选条件的帖子，按发布顺序取最多 max_messages 条。 */
    private function pendingPosts(RobotForwardConfig $config, array $filter): ?Collection
    {
        $max = max(1, (int) ($filter['max_messages'] ?? 20));
        $query = $this->configs->matchingPostsQuery($filter)
            ->where('published_at', '<=', now())
            ->where('id', '>', (int) ($config->last_forwarded_post_id ?? 0));
        $this->applyKeywords($query, $config->template_config['keywords'] ?? []);

        $posts = $query->orderBy('id')->limit($max)->get();

        return $posts->isEmpty() ? null : $posts;
    }

    /** @param array<int, string> $keywords */
    private function applyKeywords(Builder $query, array $keywords): void
    {
        $keywords = collect($keywords)->map(fn (mixed $keyword): string => trim((string) $keyword))->filter()->values();
        if ($keywords->isEmpty()) {
            return;
        }

        $query->where(function (Builder $nested) use ($keywords): void {
            foreach ($keywords as $keyword) {
                $like = '%'.$keyword.'%';
                $nested->orWhere('title', 'like', $like)->orWhere('content', 'like', $like);
            }
        });
    }

    /** @param array<string, mixed> $filter */
    private function activeToday(array $filter): bool
    {
        $weekdays = collect($filter['active_weekdays'] ?? [])->map(fn (mixed $day): int => (int) $day)->all();
        if ($weekdays === []) {
            return true;
        }

        return in_array(now()->dayOfWeekIso, $weekdays, true);
    }

    /** @param array<string, mixed> $filter */
    private function inQuietHours(array $filter): bool
    {
        $start = $this->minutes($filter['quiet_start'] ?? null);
        $end = $this->minutes($filter['quiet_end'] ?? null);
        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $now = now()->hour * 60 + now()->minute;

        return $start < $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    /** @param array<string, mixed> $filter */
    private function intervalElapsed(RobotForwardConfig $config, array $filter): bool
    {
        if ($config->last_dispatch_at === null) {
            return true;
        }

        $minutes = max(1, (int) ($filter['aggregate_interval'] ?? 1));

        return $config->last_dispatch_at->copy()->startOfMinute()->addMinutes($minutes)->lte(now()->startOfMinute());
    }

    private function admin(RobotForwardConfig $config): ?AdminUser
    {
        if ($config->created_by_admin_id !== null) {
            $admin = AdminUser::query()->find($config->created_by_admin_id);
            if ($admin !== null) {
                return $admin;
            }
        }

        return AdminUser::query()->where('status', 'active')->orderBy('id')->first();
    }

    private function minutes(mixed $value): ?int
    {
        $value = trim((string) $value);
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }
}
