<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskVersion;
use App\Modules\Task\Services\TaskClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskHallController extends Controller
{
    public function index(Request $request, TaskClaimService $claims): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'], 'category' => ['sometimes', 'in:onboarding,daily,community,commerce,research,campaign,errand,study,skill,lost_found,teamup'],
            'participation_mode' => ['sometimes', 'in:claim_and_submit,claim_and_auto_verify,auto_enroll'],
            'reward_type' => ['sometimes', 'in:points,coupon,wallet_balance'], 'task_type' => ['sometimes', 'in:one_time,daily,weekly,monthly,milestone,repeatable'],
            'sort' => ['sometimes', 'in:default,latest,reward'],
            'claimable_only' => ['sometimes', 'boolean'],
        ]);
        $items = collect($claims->hall($filters))
            ->when(($filters['sort'] ?? null) === 'latest', fn ($collection) => $collection->sortByDesc(fn (array $task) => $task['published_at'] ?? null)->values())
            ->when(($filters['sort'] ?? null) === 'reward', fn ($collection) => $collection->sortByDesc(fn (array $task) => (int) collect($task['rewards'] ?? [])->max('points'))->values());

        return ApiResponse::success([
            'items' => $items->all(),
            'withdrawal_notices' => $this->withdrawalNotices(),
            'activity_feed' => $this->activityFeed(),
            'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()],
        ]);
    }

    public function show(Task $task, TaskClaimService $claims): JsonResponse
    {
        abort_if($task->status !== 'published', 404);

        $presented = $claims->publicTask($task, true);
        abort_if($presented['visibility'] !== 'public', 404);

        return ApiResponse::success($presented);
    }

    public function claim(Request $request, Task $task, TaskClaimService $claims): JsonResponse
    {
        $claim = $claims->claim($request->user(), $task, $this->key($request));

        return ApiResponse::success($claims->presentClaim($claim), 201);
    }

    public function myClaims(Request $request, TaskClaimService $claims): JsonResponse
    {
        $group = $request->string('group')->toString();
        $query = TaskClaim::query()->where('user_id', $request->user()->id);
        if ($group !== '') {
            $statuses = match ($group) {
                'in_progress' => ['claimed', 'withdrawn', 'rejected'],
                'to_submit' => ['claimed', 'withdrawn'],
                'reviewing' => ['submitted', 'reviewing', 'appealed', 'appeal_reviewing'],
                'rejected' => ['rejected', 'appeal_rejected'],
                'completed' => ['approved'],
                'ended' => ['cancelled', 'expired', 'appeal_rejected'],
                default => [],
            };
            $query->whereIn('status', $statuses);
        }
        $items = $query->latest('id')->get()->map(fn (TaskClaim $claim) => $claims->presentClaim($claim));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function showClaim(Request $request, TaskClaim $taskClaim, TaskClaimService $claims): JsonResponse
    {
        abort_unless($taskClaim->user_id === $request->user()->id, 404);

        return ApiResponse::success($claims->presentClaim($taskClaim));
    }

    public function cancel(Request $request, TaskClaim $taskClaim, TaskClaimService $claims): JsonResponse
    {
        abort_unless($taskClaim->user_id === $request->user()->id, 404);
        $reason = $request->validate(['reason' => ['sometimes', 'string', 'max:500']])['reason'] ?? '用户主动取消未开始的任务。';
        $claim = $claims->cancel($request->user(), $taskClaim, $reason, $this->key($request));

        return ApiResponse::success($claims->presentClaim($claim));
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }

    /**
     * 任务大厅动态：真实的完成/审核通过事件 + 最新发布事件，按时间合并。
     *
     * @return array<int,array<string,mixed>>
     */
    private function activityFeed(): array
    {
        $events = [];

        TaskClaim::query()
            ->with(['task', 'user'])
            ->whereNotNull('approved_at')
            ->latest('approved_at')
            ->limit(8)
            ->get()
            ->each(function (TaskClaim $claim) use (&$events): void {
                $snapshot = is_array($claim->task_snapshot) ? $claim->task_snapshot : [];
                $points = (int) collect($snapshot['rewards'] ?? [])->where('type', 'points')->sum('points');
                $events[] = [
                    'id' => 'claim-'.$claim->public_id,
                    'type' => 'completed',
                    'actor_masked' => $this->maskActor($claim->user?->nickname, $claim->user?->phone),
                    'action_text' => '完成',
                    'task_title' => (string) ($snapshot['title'] ?? '校园任务'),
                    'reward_text' => $points > 0 ? "赚到 {$points} 积分" : '任务已完成',
                    'occurred_at' => $claim->approved_at?->toIso8601String(),
                ];
            });

        $publishedTitles = Task::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->limit(8)
            ->get()
            ->mapWithKeys(function (Task $task): array {
                $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->published_version_no)->first();
                $actor = trim((string) ($version?->content['publisher_display_name'] ?? ''));

                return [$task->id => [
                    'title' => (string) ($version?->content['title'] ?? '新任务'),
                    'actor' => $actor !== '' ? $actor : '校园任务官',
                ]];
            });

        Task::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->limit(8)
            ->get()
            ->each(function (Task $task) use (&$events, $publishedTitles): void {
                $events[] = [
                    'id' => 'publish-'.$task->public_id,
                    'type' => 'published',
                    'actor_masked' => $publishedTitles[$task->id]['actor'] ?? '校园任务官',
                    'action_text' => '发布',
                    'task_title' => $publishedTitles[$task->id]['title'] ?? '新任务',
                    'reward_text' => '快去看看',
                    'occurred_at' => $task->published_at?->toIso8601String(),
                ];
            });

        return collect($events)
            ->filter(fn (array $event): bool => ! empty($event['occurred_at']))
            ->sortByDesc(fn (array $event) => $event['occurred_at'])
            ->take(10)
            ->values()
            ->all();
    }

    private function maskActor(?string $nickname, ?string $phone): string
    {
        $nickname = trim((string) $nickname);
        if (is_string($phone) && preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches)) {
            return $matches[1].'****'.$matches[2];
        }

        return $nickname !== '' ? $nickname : '某位同学';
    }

    /** @return array<int,array<string,mixed>> */
    private function withdrawalNotices(): array
    {
        return WalletWithdrawal::query()
            ->with('user')
            ->whereNotNull('completed_at')
            ->whereNotIn('status', ['rejected', 'failed', 'cancelled'])
            ->latest('completed_at')
            ->limit(10)
            ->get()
            ->map(function (WalletWithdrawal $withdrawal): array {
                $phone = $withdrawal->user?->phone;
                $nickname = trim((string) $withdrawal->user?->nickname);
                $masked = is_string($phone) && preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches)
                    ? $matches[1].'****'.$matches[2]
                    : ($nickname !== '' ? mb_substr($nickname, 0, 1).'***' : '某位用户');

                return [
                    'id' => $withdrawal->public_id,
                    'user_masked' => $masked,
                    'withdrawal_amount' => $withdrawal->requested_amount_cents,
                    'completed_at' => $withdrawal->completed_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
