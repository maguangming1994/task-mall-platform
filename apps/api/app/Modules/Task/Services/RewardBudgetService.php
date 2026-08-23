<?php

namespace App\Modules\Task\Services;

use App\Common\Support\ApiResponse;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Models\RewardBudgetTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RewardBudgetService
{
    public function create(AdminUser $admin, array $data, string $idempotencyKey): RewardBudgetPool
    {
        $hash = $this->fingerprint($idempotencyKey);
        $existing = RewardBudgetPool::query()->where('created_by_admin_id', $admin->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($data['asset_type'] === 'coupon' && empty($data['coupon_template_id'])) {
            throw ValidationException::withMessages(['coupon_template_id' => ['优惠券预算必须指定优惠券模板。']]);
        }

        return DB::transaction(function () use ($admin, $data, $hash): RewardBudgetPool {
            if ($data['asset_type'] === 'coupon') {
                $template = CouponTemplate::query()->where('public_id', $data['coupon_template_id'])->lockForUpdate()->first();
                if ($template === null || $template->status !== 'active') {
                    throw ValidationException::withMessages(['coupon_template_id' => ['优惠券模板不存在或未启用。']]);
                }
                $this->assertCouponCapacity($template, (int) $data['total_amount']);
            }
            $pool = RewardBudgetPool::query()->create([
                'name' => $data['name'], 'asset_type' => $data['asset_type'], 'coupon_template_public_id' => $data['coupon_template_id'] ?? null,
                'total_amount' => $data['total_amount'], 'created_by_admin_id' => $admin->id, 'idempotency_key_hash' => $hash,
            ])->refresh();
            $this->record($pool, null, null, 'create', $pool->total_amount, hash('sha256', 'pool-create:'.$pool->id), $admin, $data['reason']);

            return $pool;
        });
    }

    public function adjust(AdminUser $admin, RewardBudgetPool $pool, array $data, string $idempotencyKey): RewardBudgetPool
    {
        $key = hash('sha256', 'pool-adjust:'.$pool->id.':'.$this->fingerprint($idempotencyKey));
        $existing = RewardBudgetTransaction::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $pool->fresh();
        }

        return DB::transaction(function () use ($admin, $pool, $data, $key): RewardBudgetPool {
            $locked = RewardBudgetPool::query()->lockForUpdate()->findOrFail($pool->id);
            if ($locked->version !== $data['version']) {
                throw ValidationException::withMessages(['version' => ['预算池已经被其他操作更新，请刷新后重试。']]);
            }
            $change = $data['action'] === 'increase' ? $data['amount'] : -$data['amount'];
            $newTotal = $locked->total_amount + $change;
            if ($newTotal < $locked->locked_amount + $locked->issued_amount) {
                throw ValidationException::withMessages(['amount' => ['减少后的总预算不能低于已锁定与已发放金额。']]);
            }
            if ($locked->asset_type === 'coupon' && $change > 0) {
                $template = CouponTemplate::query()->where('public_id', $locked->coupon_template_public_id)->lockForUpdate()->first();
                if ($template === null || $template->status !== 'active') {
                    throw ValidationException::withMessages(['amount' => ['绑定的优惠券模板不存在或未启用，不能增加预算。']]);
                }
                $this->assertCouponCapacity($template, max(0, $newTotal - $locked->issued_amount), $locked->id);
            }
            $locked->update(['total_amount' => $newTotal, 'version' => $locked->version + 1]);
            $this->record($locked->fresh(), null, null, 'adjust_'.$data['action'], $change, $key, $admin, $data['reason']);

            return $locked->fresh();
        });
    }

    public function present(RewardBudgetPool $pool): array
    {
        return [
            'id' => $pool->public_id, 'name' => $pool->name, 'asset_type' => $pool->asset_type,
            'coupon_template_id' => $pool->coupon_template_public_id, 'total_amount' => $pool->total_amount,
            'locked_amount' => $pool->locked_amount, 'issued_amount' => $pool->issued_amount,
            'available_amount' => max(0, $pool->total_amount - $pool->locked_amount - $pool->issued_amount),
            'status' => $pool->status, 'version' => $pool->version,
        ];
    }

    public function record(RewardBudgetPool $pool, ?int $taskId, ?int $taskBudgetId, string $type, int $change, string $key, ?AdminUser $admin, string $reason): void
    {
        RewardBudgetTransaction::query()->create([
            'reward_budget_pool_id' => $pool->id, 'task_id' => $taskId, 'task_reward_budget_id' => $taskBudgetId,
            'transaction_type' => $type, 'change_amount' => $change, 'total_after' => $pool->total_amount,
            'locked_after' => $pool->locked_amount, 'issued_after' => $pool->issued_amount, 'idempotency_key' => $key,
            'admin_user_id' => $admin?->id, 'reason' => $reason, 'request_id' => ApiResponse::requestId(),
        ]);
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }

    private function assertCouponCapacity(CouponTemplate $template, int $requestedOutstanding, ?int $excludePoolId = null): void
    {
        $otherOutstanding = RewardBudgetPool::query()
            ->where('asset_type', 'coupon')
            ->where('coupon_template_public_id', $template->public_id)
            ->where('status', 'active')
            ->when($excludePoolId !== null, fn ($query) => $query->where('id', '!=', $excludePoolId))
            ->get()
            ->sum(fn (RewardBudgetPool $pool): int => max(0, $pool->total_amount - $pool->issued_amount));
        $remaining = max(0, $template->total_quantity - $template->issued_quantity);
        if ($otherOutstanding + $requestedOutstanding > $remaining) {
            throw ValidationException::withMessages([
                'total_amount' => ['优惠券模板剩余发行量不足，需同时扣除其他预算池已占用但尚未发放的数量。'],
            ]);
        }
    }
}
