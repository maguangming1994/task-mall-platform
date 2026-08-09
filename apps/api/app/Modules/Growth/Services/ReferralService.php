<?php

namespace App\Modules\Growth\Services;

use App\Models\User;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Growth\Models\Referral;
use App\Modules\Settings\Services\SystemSettingService;
use App\Modules\Task\Models\TaskClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AssetLedgerService $assets,
    ) {}

    public function code(User $user): string
    {
        return 'TM'.strtoupper(substr($user->public_id, -10));
    }

    public function bind(User $referred, string $code): Referral
    {
        $normalized = strtoupper(trim($code));
        $referrer = User::query()->get()->first(fn (User $user): bool => hash_equals($this->code($user), $normalized));
        if ($referrer === null || $referrer->id === $referred->id) {
            throw ValidationException::withMessages(['referral_code' => ['推荐码无效或不能推荐自己。']]);
        }

        return Referral::query()->firstOrCreate(
            ['referred_user_id' => $referred->id],
            ['referrer_user_id' => $referrer->id, 'referral_code' => $normalized, 'bound_at' => now()],
        );
    }

    public function recordFirstTask(TaskClaim $claim): void
    {
        $referral = Referral::query()->where('referred_user_id', $claim->user_id)->whereNull('first_task_completed_at')->first();
        if ($referral === null || $claim->approved_at?->lt($referral->bound_at)) {
            return;
        }
        $referral->update(['first_task_claim_id' => $claim->id, 'first_task_completed_at' => $claim->approved_at ?? now()]);
        $this->qualify($referral->fresh());
    }

    public function recordFirstCashOrder(User $referred, string $orderPublicId, int $cashPaidCents, string $orderStatus): void
    {
        if ($cashPaidCents <= 0 || $orderStatus !== 'completed') {
            return;
        }
        $referral = DB::transaction(function () use ($referred, $orderPublicId) {
            $locked = Referral::query()->where('referred_user_id', $referred->id)->lockForUpdate()->first();
            if ($locked === null || $locked->first_cash_order_completed_at !== null) {
                return $locked;
            }
            $locked->update(['qualifying_order_public_id' => $orderPublicId, 'first_cash_order_completed_at' => now()]);

            return $locked->fresh();
        });
        if ($referral) {
            $this->qualify($referral);
        }
    }

    public function qualify(Referral $referral): void
    {
        if ($referral->rewarded_at !== null || $referral->revoked_at !== null) {
            return;
        }
        $required = $this->settings->read('tasks.referral_completion_milestones')['value'];
        if ((in_array('first_task', $required, true) && $referral->first_task_completed_at === null)
            || (in_array('first_cash_order', $required, true) && $referral->first_cash_order_completed_at === null)) {
            return;
        }
        $cap = (int) $this->settings->read('tasks.referral_monthly_cap')['value'];
        $rewardedThisMonth = Referral::query()->where('referrer_user_id', $referral->referrer_user_id)->whereBetween('rewarded_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        if ($rewardedThisMonth >= $cap) {
            $referral->update(['status' => 'qualified_cap_reached', 'qualified_at' => now()]);

            return;
        }
        DB::transaction(function () use ($referral): void {
            $locked = Referral::query()->lockForUpdate()->findOrFail($referral->id);
            if ($locked->rewarded_at !== null) {
                return;
            }
            $event = 'referral-qualified:'.$locked->public_id;
            $points = (int) ($this->settings->read('points.source_reward_amounts')['value']['referral'] ?? 0);
            $this->assets->grantPoints(User::query()->findOrFail($locked->referrer_user_id), $points, 'referral_new_user', $locked->public_id, '推荐新人完成首个任务和首笔现金订单', $event);
            $locked->update(['status' => 'rewarded', 'qualified_at' => now(), 'reward_event_key' => $event, 'rewarded_at' => now()]);
        });
    }

    public function progress(User $user): array
    {
        return Referral::query()->where('referrer_user_id', $user->id)->latest('id')->get()->map(function (Referral $referral): array {
            $referred = User::query()->findOrFail($referral->referred_user_id);

            return [
                'id' => $referral->public_id, 'referred_user' => ['nickname' => $this->mask($referred->nickname)],
                'first_task_completed' => $referral->first_task_completed_at !== null,
                'first_cash_order_completed' => $referral->first_cash_order_completed_at !== null,
                'status' => $referral->status, 'rewarded_at' => $referral->rewarded_at?->toIso8601String(),
            ];
        })->all();
    }

    private function mask(?string $nickname): string
    {
        $value = trim($nickname ?: '新用户');

        return mb_substr($value, 0, 1).'***';
    }
}
