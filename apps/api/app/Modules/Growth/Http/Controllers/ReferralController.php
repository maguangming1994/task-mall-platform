<?php

namespace App\Modules\Growth\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Growth\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index(Request $request, ReferralService $referrals): JsonResponse
    {
        $items = $referrals->progress($request->user());

        return ApiResponse::success(['referral_code' => $referrals->code($request->user()), 'items' => $items, 'pagination' => ['page' => 1, 'page_size' => count($items), 'total' => count($items)]]);
    }

    public function bind(Request $request, ReferralService $referrals): JsonResponse
    {
        $code = $request->validate(['referral_code' => ['required', 'string', 'min:4', 'max:32']])['referral_code'];
        $referral = $referrals->bind($request->user(), $code);

        return ApiResponse::success(['id' => $referral->public_id, 'status' => $referral->status], 201);
    }
}
