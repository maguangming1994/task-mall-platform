<?php

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Privacy\Models\LegalHold;
use App\Modules\Privacy\Models\UserAccountConstraint;

class AccountEligibilityService
{
    /** @return array<string,mixed> */
    public function evaluate(User $user): array
    {
        $blockers = UserAccountConstraint::query()->where('user_id', $user->id)->whereNull('resolved_at')->pluck('blocker_code')->unique()->values()->all();
        $legalHold = LegalHold::query()->where('object_type', 'user')->where('object_id', $user->public_id)->whereNull('released_at')
            ->where('starts_at', '<=', now())->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists();
        if ($legalHold) {
            $blockers[] = 'active_dispute';
        }
        $blockers = array_values(array_unique($blockers));

        $assets = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->refresh();
        if ($assets->wallet_available_cents > 0) {
            $blockers[] = 'wallet_balance_not_zero';
        }
        if ($assets->wallet_frozen_cents > 0) {
            $blockers[] = 'frozen_assets';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'eligible' => $blockers === [],
            'blockers' => $blockers,
            'wallet_balance_amount' => $assets->wallet_available_cents,
            'frozen_assets' => in_array('frozen_assets', $blockers, true),
            'remaining_points' => $assets->points_available,
            'remaining_coupons' => $assets->coupon_available,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
