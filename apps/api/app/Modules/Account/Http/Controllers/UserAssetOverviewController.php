<?php

namespace App\Modules\Account\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Account\Models\UserMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAssetOverviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $account = UserAssetAccount::query()->firstOrCreate(['user_id' => $user->id])->refresh();
        $milestones = UserMilestone::query()->where('user_id', $user->id)->get()->keyBy('milestone_code');

        return ApiResponse::success([
            'points' => ['available' => $account->points_available, 'frozen' => $account->points_frozen, 'debt' => $account->points_debt],
            'wallet' => ['available_amount' => $account->wallet_available_cents, 'frozen_amount' => $account->wallet_frozen_cents, 'currency' => 'CNY'],
            'coupons' => ['available' => $account->coupon_available],
            'profile' => [
                'completed' => $user->profile_completed_at !== null,
                'reward_status' => $milestones->get('profile_completed')?->reward_status ?? 'not_eligible',
            ],
            'version' => $account->version,
        ]);
    }
}
