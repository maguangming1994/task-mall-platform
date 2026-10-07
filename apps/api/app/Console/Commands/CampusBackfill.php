<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Models\CampusMembershipChange;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Community\Models\CommunityActivity;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\Task\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CampusBackfill extends Command
{
    protected $signature = 'campus:backfill
                            {--dry-run : 只输出报告，不执行变更}
                            {--only= : 只执行某一步（users|ugc|tasks|redundant）}
                            {--platform-tasks= : 平台投放任务 id 列表，逗号分隔}
                            {--batch= : 指定批次号（默认自动生成）}';

    protected $description = '校区强隔离存量数据回填：用户/UGC/任务/冗余字段';

    private array $report = [];

    private array $rollback = [];

    private string $batch;

    private bool $dryRun;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->batch = $this->option('batch') ?: now()->format('YmdHis').'-'.$this->getRunTime();
        $only = $this->option('only');

        $this->info("批次号：{$this->batch}".($this->dryRun ? '（dry-run）' : ''));

        DB::transaction(function () use ($only): void {
            if (! $only || $only === 'users') {
                $this->backfillUsers();
            }
            if (! $only || $only === 'ugc') {
                $this->backfillUgc();
            }
            if (! $only || $only === 'tasks') {
                $this->backfillTasks();
            }
            if (! $only || $only === 'redundant') {
                $this->backfillRedundant();
            }
        });

        $this->validateAll();
        $this->writeReport();

        $this->info($this->dryRun ? 'Dry-run 完成。' : '回填完成。');

        return $this->hasValidationFailures() ? 1 : 0;
    }

    private function backfillUsers(): void
    {
        $this->info('Step 1: 用户回填...');

        $defaultCampus = Campus::query()->where('public_id', 'default-campus')->first();
        if ($defaultCampus === null) {
            $this->error('未找到 default-campus，请先创建默认学校。');
            $this->report['users'] = ['error' => 'default-campus not found'];

            return;
        }

        $users = User::query()->whereNull('campus_id')->get();
        $inferred = 0;
        $toDefault = 0;

        foreach ($users as $user) {
            $oldCampusId = $user->campus_id;
            $campusId = $this->inferUserCampus($user) ?? $defaultCampus->id;

            if (! $this->dryRun) {
                $user->update(['campus_id' => $campusId]);
                CampusMembershipChange::query()->create([
                    'user_id' => $user->id,
                    'from_campus_id' => $oldCampusId,
                    'to_campus_id' => $campusId,
                    'reason' => $this->inferUserCampus($user) ? 'backfill_inferred' : 'backfill_default',
                ]);
            }

            $this->rollback[] = [
                'table' => 'users',
                'id' => $user->id,
                'old_campus_id' => $oldCampusId,
                'new_campus_id' => $campusId,
            ];

            if ($this->inferUserCampus($user)) {
                $inferred++;
            } else {
                $toDefault++;
            }
        }

        $this->report['users'] = [
            'total_null' => $users->count(),
            'inferred' => $inferred,
            'to_default' => $toDefault,
        ];

        $this->info("  共 {$users->count()} 条，推断 {$inferred} 条，兜底 {$toDefault} 条");
    }

    private function backfillUgc(): void
    {
        $this->info('Step 2: UGC 回填...');

        $posts = CommunityPost::query()->whereNull('campus_id')->get();
        $items = SecondHandItem::query()->whereNull('campus_id')->get();
        $activities = CommunityActivity::query()->whereNull('campus_id')->get();

        $conflicts = 0;
        $backfilled = 0;

        foreach (array_merge($posts->all(), $items->all(), $activities->all()) as $content) {
            $author = $content->user ?? $content->author ?? null;
            if ($author === null) {
                $conflicts++;

                continue;
            }
            $authorCampusId = $author->campus_id;
            if ($authorCampusId === null) {
                $conflicts++;

                continue;
            }

            if (! $this->dryRun) {
                $content->update(['campus_id' => $authorCampusId]);
            }

            $this->rollback[] = [
                'table' => $content->getTable(),
                'id' => $content->id,
                'old_campus_id' => null,
                'new_campus_id' => $authorCampusId,
            ];
            $backfilled++;
        }

        $this->report['ugc'] = [
            'posts_null' => $posts->count(),
            'items_null' => $items->count(),
            'activities_null' => $activities->count(),
            'backfilled' => $backfilled,
            'conflicts' => $conflicts,
        ];

        $this->info("  回填 {$backfilled} 条，冲突 {$conflicts} 条");
    }

    private function backfillTasks(): void
    {
        $this->info('Step 3: 任务回填...');

        $platformTaskIds = collect(explode(',', (string) $this->option('platform-tasks')))
            ->filter()
            ->map(fn ($id) => (int) trim($id))
            ->all();

        $nullTasks = Task::query()->whereNull('campus_id')->get();
        $platformWide = 0;
        $campusBackfill = 0;
        $toDraft = 0;

        foreach ($nullTasks as $task) {
            if (in_array($task->id, $platformTaskIds, true)) {
                if (! $this->dryRun) {
                    $task->update(['is_platform_wide' => true]);
                }
                $this->rollback[] = [
                    'table' => 'tasks',
                    'id' => $task->id,
                    'old_is_platform_wide' => false,
                    'new_is_platform_wide' => true,
                ];
                $platformWide++;
            } else {
                // 尝试从发布者归属推断
                $publisherCampusId = $task->publisher?->campus_id;
                if ($publisherCampusId !== null) {
                    if (! $this->dryRun) {
                        $task->update(['campus_id' => $publisherCampusId]);
                    }
                    $this->rollback[] = [
                        'table' => 'tasks',
                        'id' => $task->id,
                        'old_campus_id' => null,
                        'new_campus_id' => $publisherCampusId,
                    ];
                    $campusBackfill++;
                } else {
                    if (! $this->dryRun) {
                        $task->update(['status' => 'draft']);
                    }
                    $this->rollback[] = [
                        'table' => 'tasks',
                        'id' => $task->id,
                        'old_status' => $task->status,
                        'new_status' => 'draft',
                    ];
                    $toDraft++;
                }
            }
        }

        $this->report['tasks'] = [
            'total_null' => $nullTasks->count(),
            'platform_wide' => $platformWide,
            'campus_backfill' => $campusBackfill,
            'to_draft' => $toDraft,
        ];

        $this->info("  平台投放 {$platformWide} 条，校本回填 {$campusBackfill} 条，下线 {$toDraft} 条");
    }

    private function backfillRedundant(): void
    {
        $this->info('Step 4: 冗余字段回填...');

        $orders = SecondHandOrder::query()->whereNull('campus_id')->get();
        $conversations = ChatConversation::query()->whereNull('campus_id')->get();

        $orderCount = 0;
        foreach ($orders as $order) {
            $item = $order->item;
            if ($item !== null && $item->campus_id !== null) {
                if (! $this->dryRun) {
                    $order->update(['campus_id' => $item->campus_id]);
                }
                $this->rollback[] = [
                    'table' => 'second_hand_orders',
                    'id' => $order->id,
                    'old_campus_id' => null,
                    'new_campus_id' => $item->campus_id,
                ];
                $orderCount++;
            }
        }

        $conversationCount = 0;
        foreach ($conversations as $conversation) {
            $item = $conversation->item;
            if ($item !== null && $item->campus_id !== null) {
                if (! $this->dryRun) {
                    $conversation->update(['campus_id' => $item->campus_id]);
                }
                $this->rollback[] = [
                    'table' => 'chat_conversations',
                    'id' => $conversation->id,
                    'old_campus_id' => null,
                    'new_campus_id' => $item->campus_id,
                ];
                $conversationCount++;
            }
        }

        $this->report['redundant'] = [
            'second_hand_orders' => $orderCount,
            'chat_conversations' => $conversationCount,
        ];

        $this->info("  订单 {$orderCount} 条，会话 {$conversationCount} 条");
    }

    private function validateAll(): void
    {
        $this->info('Step 5: 校验...');

        $failures = [];

        $nullPosts = CommunityPost::query()->whereNull('campus_id')->count();
        if ($nullPosts > 0) {
            $failures[] = "community_posts 仍有 {$nullPosts} 条 campus_id 为 NULL";
        }

        $nullItems = SecondHandItem::query()->whereNull('campus_id')->count();
        if ($nullItems > 0) {
            $failures[] = "second_hand_items 仍有 {$nullItems} 条 campus_id 为 NULL";
        }

        $nullUsers = User::query()->whereNull('campus_id')->where('status', 'active')->count();
        if ($nullUsers > 0) {
            $failures[] = "users 仍有 {$nullUsers} 条 active 用户无校";
        }

        $orphanPosts = CommunityPost::query()
            ->leftJoin('campuses', 'campuses.id', '=', 'community_posts.campus_id')
            ->whereNull('campuses.id')
            ->count();
        if ($orphanPosts > 0) {
            $failures[] = "community_posts 有 {$orphanPosts} 条指向无效校区";
        }

        $this->report['validation'] = [
            'passed' => count($failures) === 0,
            'failures' => $failures,
        ];

        if (count($failures) > 0) {
            foreach ($failures as $f) {
                $this->error("  {$f}");
            }
        } else {
            $this->info('  全部通过');
        }
    }

    private function writeReport(): void
    {
        $dir = 'backfill';
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);

        $reportPath = "{$dir}/{$this->batch}-report.json";
        $disk->put($reportPath, json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("报告已写入：storage/app/{$reportPath}");

        if (! $this->dryRun && count($this->rollback) > 0) {
            $rollbackPath = "{$dir}/{$this->batch}-rollback.json";
            $disk->put($rollbackPath, json_encode($this->rollback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("回滚映射已写入：storage/app/{$rollbackPath}");
        }
    }

    private function hasValidationFailures(): bool
    {
        return ! ($this->report['validation']['passed'] ?? true);
    }

    private function inferUserCampus(User $user): ?int
    {
        // 从最近订单/地址/发布内容推断校区
        $latestOrder = $user->orders()->latest()->first();
        if ($latestOrder?->campus_id !== null) {
            return (int) $latestOrder->campus_id;
        }

        $latestAddress = $user->addresses()->latest()->first();
        if ($latestAddress?->campus_id !== null) {
            return (int) $latestAddress->campus_id;
        }

        $latestPost = $user->communityPosts()->latest()->first();
        if ($latestPost?->campus_id !== null) {
            return (int) $latestPost->campus_id;
        }

        $latestItem = $user->secondHandItems()->latest()->first();
        if ($latestItem?->campus_id !== null) {
            return (int) $latestItem->campus_id;
        }

        return null;
    }

    private function getRunTime(): string
    {
        return substr(md5(uniqid()), 0, 6);
    }
}
