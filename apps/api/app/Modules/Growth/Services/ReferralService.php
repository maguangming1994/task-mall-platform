<?php

namespace App\Modules\Growth\Services;

use App\Models\User;
use App\Modules\Assets\Models\PointTransaction;
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
        $required = $this->requiredMilestones();
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
        DB::transaction(function () use ($referral, $required): void {
            $locked = Referral::query()->lockForUpdate()->findOrFail($referral->id);
            if ($locked->rewarded_at !== null) {
                return;
            }
            $event = 'referral-qualified:'.$locked->public_id;
            $points = (int) ($this->settings->read('points.source_reward_amounts')['value']['referral'] ?? 0);
            $this->assets->grantPoints(
                User::query()->findOrFail($locked->referrer_user_id),
                $points,
                'referral_new_user',
                $locked->public_id,
                '推荐好友完成'.implode('、', array_map(fn (string $code): string => $this->milestoneDefinition($code)['label'], $required)),
                $event,
            );
            $locked->update(['status' => 'rewarded', 'qualified_at' => now(), 'reward_event_key' => $event, 'rewarded_at' => now()]);
        });
    }

    /** @return array<string,mixed> */
    public function dashboard(User $user): array
    {
        $milestoneSetting = $this->settings->read('tasks.referral_completion_milestones');
        $rewardSetting = $this->settings->read('points.source_reward_amounts');
        $capSetting = $this->settings->read('tasks.referral_monthly_cap');
        $required = $this->requiredMilestones($milestoneSetting['value']);
        $configuredPoints = (int) ($rewardSetting['value']['referral'] ?? 0);
        $monthlyCap = max(0, (int) $capSetting['value']);
        $referrals = Referral::query()
            ->with('referred')
            ->where('referrer_user_id', $user->id)
            ->latest('id')
            ->get();
        $transactions = PointTransaction::query()
            ->where('user_id', $user->id)
            ->where('source_code', 'referral_new_user')
            ->whereIn('source_business_id', $referrals->pluck('public_id'))
            ->get()
            ->keyBy('source_business_id');

        $items = $referrals->map(function (Referral $referral) use ($required, $transactions): array {
            $transaction = $transactions->get($referral->public_id);
            $reward = $transaction === null ? null : $this->rewardRecord($referral, $transaction);

            return [
                'id' => $referral->public_id,
                'referred_user' => ['nickname' => $this->mask($referral->referred?->nickname)],
                'bound_at' => $referral->bound_at?->toIso8601String(),
                'milestones' => array_map(fn (string $code): array => $this->milestoneProgress($referral, $code), $required),
                'first_task_completed' => $referral->first_task_completed_at !== null,
                'first_cash_order_completed' => $referral->first_cash_order_completed_at !== null,
                'status' => $referral->status,
                'qualified_at' => $referral->qualified_at?->toIso8601String(),
                'reward_points' => $reward['points'] ?? 0,
                'reward' => $reward,
                'rewarded_at' => $referral->rewarded_at?->toIso8601String(),
            ];
        })->all();
        $rewardRecords = array_values(array_filter(array_map(fn (array $item): ?array => $item['reward'], $items)));
        $rewardedThisMonth = $referrals
            ->filter(fn (Referral $referral): bool => $referral->rewarded_at?->betweenIncluded(now()->startOfMonth(), now()->endOfMonth()) ?? false)
            ->count();

        return [
            'referral_code' => $this->code($user),
            'share' => [
                'path' => '/pages/referrals/index?referral_code='.rawurlencode($this->code($user)),
            ],
            'rules' => [
                'match_mode' => 'all',
                'milestones' => array_map(fn (string $code): array => $this->milestoneDefinition($code), $required),
                'reward' => ['asset_type' => 'points', 'points' => $configuredPoints],
                'monthly_cap' => $monthlyCap,
                'versions' => [
                    'milestones' => $milestoneSetting['version'],
                    'reward' => $rewardSetting['version'],
                    'monthly_cap' => $capSetting['version'],
                ],
            ],
            'summary' => [
                'invited_count' => $referrals->count(),
                'rewarded_count' => $referrals->whereNotNull('rewarded_at')->count(),
                'reward_points_total' => array_sum(array_column($rewardRecords, 'points')),
                'rewarded_this_month' => $rewardedThisMonth,
                'remaining_rewards_this_month' => max(0, $monthlyCap - $rewardedThisMonth),
            ],
            'reward_records' => $rewardRecords,
            'items' => $items,
            'pagination' => ['page' => 1, 'page_size' => count($items), 'total' => count($items)],
        ];
    }

    public function progress(User $user): array
    {
        return $this->dashboard($user)['items'];
    }

    /** @return array<int,string> */
    private function requiredMilestones(mixed $configured = null): array
    {
        $configured ??= $this->settings->read('tasks.referral_completion_milestones')['value'];
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $configured,
            fn (mixed $code): bool => is_string($code) && in_array($code, ['first_task', 'first_cash_order'], true),
        )));
    }

    /** @return array{code:string,label:string,description:string} */
    private function milestoneDefinition(string $code): array
    {
        return match ($code) {
            'first_task' => [
                'code' => $code,
                'label' => '受邀人完成首次任务',
                'description' => '受邀人绑定推荐关系后，首次通过审核的任务计入进度。',
            ],
            'first_cash_order' => [
                'code' => $code,
                'label' => '受邀人完成首笔现金订单',
                'description' => '订单必须包含实际现金或余额支付，并在完成订单后计入进度。',
            ],
        };
    }

    /** @return array{code:string,label:string,description:string,completed:bool,completed_at:?string} */
    private function milestoneProgress(Referral $referral, string $code): array
    {
        $definition = $this->milestoneDefinition($code);
        $completedAt = match ($code) {
            'first_task' => $referral->first_task_completed_at,
            'first_cash_order' => $referral->first_cash_order_completed_at,
        };

        return [
            ...$definition,
            'completed' => $completedAt !== null,
            'completed_at' => $completedAt?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function rewardRecord(Referral $referral, PointTransaction $transaction): array
    {
        $availablePoints = max(0, $transaction->change_available);
        $debtRepaidPoints = max(0, -$transaction->change_debt);

        return [
            'id' => $transaction->public_id,
            'referral_id' => $referral->public_id,
            'referred_user' => ['nickname' => $this->mask($referral->referred?->nickname)],
            'asset_type' => 'points',
            'points' => $availablePoints + $debtRepaidPoints,
            'available_points' => $availablePoints,
            'debt_repaid_points' => $debtRepaidPoints,
            'credited_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    private function mask(?string $nickname): string
    {
        $value = trim($nickname ?: '新用户');

        return mb_substr($value, 0, 1).'***';
    }
}
