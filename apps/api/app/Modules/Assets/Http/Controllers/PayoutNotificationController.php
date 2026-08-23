<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Services\WalletWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutNotificationController extends Controller
{
    public function store(Request $request, WalletWithdrawalService $service): JsonResponse
    {
        $service->handleProviderNotification([
            'signature' => $request->header('X-Payout-Signature'),
            'timestamp' => $request->header('X-Payout-Timestamp'),
        ], $request->getContent());

        return response()->json(['code' => 'SUCCESS', 'message' => '成功']);
    }
}
