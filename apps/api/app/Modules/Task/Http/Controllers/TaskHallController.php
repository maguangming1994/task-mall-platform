<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\WalletWithdrawal;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Services\TaskClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskHallController extends Controller
{
    public function index(Request $request, TaskClaimService $claims): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'], 'category' => ['sometimes', 'in:onboarding,daily,community,commerce,research,campaign'],
            'participation_mode' => ['sometimes', 'in:claim_and_submit,claim_and_auto_verify,auto_enroll'],
            'reward_type' => ['sometimes', 'in:points,coupon,wallet_balance'], 'task_type' => ['sometimes', 'in:one_time,daily,weekly,repeatable'],
            'claimable_only' => ['sometimes', 'boolean'],
        ]);
        $items = collect($claims->hall($filters));

        return ApiResponse::success([
            'items' => $items,
            'withdrawal_notices' => $this->withdrawalNotices(),
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
