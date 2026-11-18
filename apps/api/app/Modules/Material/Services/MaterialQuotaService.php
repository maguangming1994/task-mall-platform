<?php

namespace App\Modules\Material\Services;

use App\Models\User;
use App\Modules\Material\Models\Material;
use App\Modules\Material\Models\MaterialFetch;
use App\Modules\Material\Models\MaterialUserQuota;
use Illuminate\Support\Facades\DB;

/**
 * 资料获取额度：每日免费次数（次日重置）+ 投喂审核通过赠送的长期次数。
 */
class MaterialQuotaService
{
    public function dailyLimit(): int
    {
        return max(0, (int) config('material.daily_limit', 1));
    }

    public function bonusPerApproved(): int
    {
        return max(0, (int) config('material.bonus_per_approved', 10));
    }

    /**
     * @return array{daily_limit: int, daily_used: int, daily_remaining: int, bonus_quota: int, total_remaining: int}
     */
    public function summary(User $user): array
    {
        $quota = $this->freshQuota($user);
        $limit = $this->dailyLimit();
        $dailyRemaining = max(0, $limit - (int) $quota->daily_used);

        return [
            'daily_limit' => $limit,
            'daily_used' => (int) $quota->daily_used,
            'daily_remaining' => $dailyRemaining,
            'bonus_quota' => (int) $quota->bonus_quota,
            'total_remaining' => $dailyRemaining + (int) $quota->bonus_quota,
        ];
    }

    /**
     * 获取资料并扣减额度；同一用户重复获取同一资料不再扣减。
     *
     * @return array{already_owned: bool, remaining: int}|null null 表示额度已用完
     */
    public function consume(User $user, Material $material): ?array
    {
        return DB::transaction(function () use ($user, $material): ?array {
            $quota = MaterialUserQuota::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['daily_date' => today(), 'daily_used' => 0, 'bonus_quota' => 0],
            );
            $quota = MaterialUserQuota::query()->lockForUpdate()->find($quota->id);
            $this->resetIfStale($quota);

            $already = MaterialFetch::query()
                ->where('material_id', $material->id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $already) {
                $limit = $this->dailyLimit();
                $dailyRemaining = max(0, $limit - (int) $quota->daily_used);

                if ($dailyRemaining > 0) {
                    $quota->daily_used++;
                } elseif ((int) $quota->bonus_quota > 0) {
                    $quota->bonus_quota--;
                } else {
                    return null;
                }
                $quota->save();

                MaterialFetch::query()->create([
                    'material_id' => $material->id,
                    'user_id' => $user->id,
                    'campus_id' => $user->campus_id,
                ]);
                $material->increment('fetch_count');
            }

            $limit = $this->dailyLimit();
            $remaining = max(0, $limit - (int) $quota->daily_used) + (int) $quota->bonus_quota;

            return ['already_owned' => $already, 'remaining' => $remaining];
        });
    }

    /** 投喂审核通过后赠送长期额度 */
    public function grantBonus(User $user, ?int $amount = null): void
    {
        $amount ??= $this->bonusPerApproved();
        if ($amount <= 0) {
            return;
        }

        $quota = MaterialUserQuota::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['daily_date' => today(), 'daily_used' => 0, 'bonus_quota' => 0],
        );
        $quota->increment('bonus_quota', $amount);
    }

    private function freshQuota(User $user): MaterialUserQuota
    {
        $quota = MaterialUserQuota::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['daily_date' => today(), 'daily_used' => 0, 'bonus_quota' => 0],
        );
        if ($this->resetIfStale($quota)) {
            $quota->save();
        }

        return $quota;
    }

    private function resetIfStale(MaterialUserQuota $quota): bool
    {
        $today = today()->toDateString();
        if ($quota->daily_date?->toDateString() === $today) {
            return false;
        }
        $quota->daily_date = today();
        $quota->daily_used = 0;

        return true;
    }
}
