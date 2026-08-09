<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskRewardBudget;
use App\Modules\Task\Models\TaskRewardDelivery;
use App\Modules\Task\Models\TaskRewardReservation;
use Illuminate\Support\Facades\DB;
use Throwable;

class TaskRewardDeliveryService
{
    public function __construct(
        private readonly AssetLedgerService $assets,
        private readonly RewardBudgetService $budgets,
    ) {}

    public function prepare(TaskClaim $claim): void
    {
        $rewards = collect($claim->task_snapshot['rewards'] ?? [])->values();
        $claim->reservations()->orderBy('reward_sequence')->get()->each(function (TaskRewardReservation $reservation) use ($claim, $rewards): void {
            TaskRewardDelivery::query()->firstOrCreate(
                ['task_reward_reservation_id' => $reservation->id],
                [
                    'task_claim_id' => $claim->id,
                    'asset_type' => $reservation->asset_type,
                    'amount' => $reservation->amount,
                    'reward_snapshot' => $rewards->get($reservation->reward_sequence - 1, ['type' => $reservation->asset_type]),
                ],
            );
        });
    }

    public function deliverForClaim(TaskClaim $claim): TaskClaim
    {
        $this->prepare($claim);
        TaskRewardDelivery::query()->where('task_claim_id', $claim->id)->whereIn('status', ['pending', 'failed'])->orderBy('id')->get()
            ->each(fn (TaskRewardDelivery $delivery) => $this->attempt($delivery));
        $this->syncClaimRewardStatus($claim);

        return $claim->fresh('rewardDeliveries');
    }

    public function retryDue(int $limit = 100): int
    {
        $count = 0;
        TaskRewardDelivery::query()->where('status', 'failed')->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get()->each(function (TaskRewardDelivery $delivery) use (&$count): void {
                $this->attempt($delivery);
                $this->syncClaimRewardStatus(TaskClaim::query()->findOrFail($delivery->task_claim_id));
                $count++;
            });

        return $count;
    }

    public function attempt(TaskRewardDelivery $delivery): TaskRewardDelivery
    {
        if ($delivery->status === 'delivered') {
            return $delivery;
        }

        try {
            DB::transaction(function () use ($delivery): void {
                $locked = TaskRewardDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
                if ($locked->status === 'delivered') {
                    return;
                }
                $claim = TaskClaim::query()->lockForUpdate()->findOrFail($locked->task_claim_id);
                if ($claim->status !== 'approved') {
                    throw new \RuntimeException('Only approved task claims can receive rewards.');
                }
                $reservation = TaskRewardReservation::query()->lockForUpdate()->findOrFail($locked->task_reward_reservation_id);
                if ($reservation->status !== 'reserved') {
                    throw new \RuntimeException('Reward reservation is no longer available.');
                }
                $budget = TaskRewardBudget::query()->lockForUpdate()->findOrFail($reservation->task_reward_budget_id);
                $pool = RewardBudgetPool::query()->lockForUpdate()->findOrFail($budget->reward_budget_pool_id);
                $user = User::query()->findOrFail($claim->user_id);
                $eventKey = 'task-reward:'.$locked->public_id;

                match ($locked->asset_type) {
                    'points' => $this->assets->grantPoints($user, $locked->amount, 'task_reward', $claim->public_id, '任务完成奖励', $eventKey),
                    'wallet_cash' => $this->assets->changeWallet($user, $locked->amount, '任务完成奖励', $eventKey, null, 'task_reward'),
                    'coupon' => $this->assets->issueCoupons(
                        $user,
                        CouponTemplate::query()->where('public_id', $pool->coupon_template_public_id)->firstOrFail(),
                        $locked->amount,
                        '任务完成奖励',
                        $eventKey,
                    ),
                    default => throw new \RuntimeException('Unsupported reward asset type.'),
                };

                if ($budget->reserved_amount < $reservation->amount || $pool->locked_amount < $reservation->amount) {
                    throw new \RuntimeException('Reward budget settlement mismatch.');
                }
                $budget->update([
                    'reserved_amount' => $budget->reserved_amount - $reservation->amount,
                    'issued_amount' => $budget->issued_amount + $reservation->amount,
                    'version' => $budget->version + 1,
                ]);
                $pool->update([
                    'locked_amount' => $pool->locked_amount - $reservation->amount,
                    'issued_amount' => $pool->issued_amount + $reservation->amount,
                    'version' => $pool->version + 1,
                ]);
                $reservation->update(['status' => 'issued', 'settled_at' => now()]);
                $locked->update([
                    'status' => 'delivered', 'attempt_count' => $locked->attempt_count + 1,
                    'last_error_code' => null, 'last_error_message' => null, 'last_attempted_at' => now(),
                    'next_retry_at' => null, 'delivered_at' => now(),
                ]);
                $this->budgets->record(
                    $pool->fresh(), $claim->task_id, $budget->id, 'task_issue', $reservation->amount,
                    hash('sha256', 'task-issue:'.$reservation->id), null, '任务奖励发放',
                );
            });
        } catch (Throwable $exception) {
            TaskRewardDelivery::query()->whereKey($delivery->id)->where('status', '!=', 'delivered')->update([
                'status' => 'failed',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_error_code' => class_basename($exception),
                'last_error_message' => mb_substr($exception->getMessage(), 0, 500),
                'last_attempted_at' => now(),
                'next_retry_at' => now()->addMinutes(min(60, 2 ** min(5, $delivery->attempt_count + 1))),
                'updated_at' => now(),
            ]);
        }

        return $delivery->fresh();
    }

    public function manualRetry(TaskRewardDelivery $delivery, AdminUser $admin, string $reason, string $idempotencyKey): TaskRewardDelivery
    {
        $hash = hash_hmac('sha256', 'task-reward-retry:'.$delivery->id.':'.$idempotencyKey, hash('sha256', (string) config('admin_auth.hash_secret')));
        $inserted = DB::table('task_reward_delivery_attempts')->insertOrIgnore([
            'task_reward_delivery_id' => $delivery->id, 'source' => 'admin', 'idempotency_key_hash' => $hash,
            'admin_user_id' => $admin->id, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return $delivery->fresh();
        }

        return $this->attempt($delivery);
    }

    private function syncClaimRewardStatus(TaskClaim $claim): void
    {
        $statuses = TaskRewardDelivery::query()->where('task_claim_id', $claim->id)->pluck('status');
        $status = $statuses->isNotEmpty() && $statuses->every(fn (string $value): bool => $value === 'delivered')
            ? 'delivered'
            : ($statuses->contains('delivered') ? 'partially_delivered' : 'delivery_failed');
        $claim->update(['reward_status' => $status]);
    }
}
