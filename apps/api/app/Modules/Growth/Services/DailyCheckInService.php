<?php

namespace App\Modules\Growth\Services;

use App\Models\User;
use App\Modules\Assets\Services\AssetLedgerService;
use App\Modules\Growth\Models\DailyCheckIn;
use App\Modules\Settings\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DailyCheckInService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly AssetLedgerService $assets,
    ) {}

    /** @return array<string,mixed> */
    public function status(User $user): array
    {
        $today = CarbonImmutable::today();
        $todayRecord = DailyCheckIn::query()
            ->where('user_id', $user->id)
            ->whereDate('check_in_date', $today)
            ->first();
        $latest = DailyCheckIn::query()->where('user_id', $user->id)->latest('check_in_date')->first();
        $weekStart = $today->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        $records = DailyCheckIn::query()
            ->where('user_id', $user->id)
            ->whereBetween('check_in_date', [$weekStart, $weekEnd])
            ->get()
            ->keyBy(fn (DailyCheckIn $item): string => $item->check_in_date->toDateString());

        $week = [];
        foreach (range(0, 6) as $offset) {
            $date = $weekStart->addDays($offset);
            $record = $records->get($date->toDateString());
            $week[] = [
                'date' => $date->toDateString(),
                'weekday' => ['周一', '周二', '周三', '周四', '周五', '周六', '周日'][$offset],
                'is_today' => $date->isSameDay($today),
                'checked_in' => $record !== null,
                'reward_points' => $record?->reward_points ?? 0,
            ];
        }

        $rewardSetting = $this->settings->read('points.source_reward_amounts');
        $validitySetting = $this->settings->read('points.source_validity_days');
        $reward = max(0, (int) ($rewardSetting['value']['sign_in'] ?? 0));
        $validity = max(1, (int) ($validitySetting['value']['sign_in'] ?? 90));

        return [
            'today' => $today->toDateString(),
            'checked_in_today' => $todayRecord !== null,
            'current_streak' => $todayRecord?->streak_days ?? ($latest?->check_in_date?->isYesterday() ? $latest->streak_days : 0),
            'today_reward_points' => $todayRecord?->reward_points ?? $reward,
            'week' => $week,
            'rules' => [
                'daily_reward_points' => $reward,
                'validity_days' => $validity,
                'reward_source' => '每日签到',
                'description' => "每日可签到一次，奖励积分有效期为 {$validity} 天。中断后连续天数重新计算。",
                'versions' => ['reward' => $rewardSetting['version'], 'validity' => $validitySetting['version']],
            ],
            'workflow' => [
                'status_label' => $todayRecord ? '今日已签到' : '今日待签到',
                'next_action' => $todayRecord ? '明天再来，继续累计连续签到天数。' : "签到后立即获得 {$reward} 积分。",
                'can_check_in' => $todayRecord === null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function checkIn(User $user): array
    {
        $today = CarbonImmutable::today();
        DB::transaction(function () use ($user, $today): void {
            $existing = DailyCheckIn::query()
                ->where('user_id', $user->id)
                ->whereDate('check_in_date', $today)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return;
            }

            $previous = DailyCheckIn::query()
                ->where('user_id', $user->id)
                ->latest('check_in_date')
                ->lockForUpdate()
                ->first();
            $streak = $previous?->check_in_date?->isYesterday() ? $previous->streak_days + 1 : 1;
            $reward = max(0, (int) ($this->settings->read('points.source_reward_amounts')['value']['sign_in'] ?? 0));
            $eventKey = 'daily-check-in:'.$user->public_id.':'.$today->toDateString();
            $record = DailyCheckIn::query()->create([
                'user_id' => $user->id,
                'check_in_date' => $today,
                'streak_days' => $streak,
                'reward_points' => $reward,
                'reward_event_key' => $eventKey,
            ]);
            if ($reward > 0) {
                $this->assets->grantPoints($user, $reward, 'daily_check_in', $record->public_id, '每日签到奖励', $eventKey);
            }
        });

        return $this->status($user);
    }
}
