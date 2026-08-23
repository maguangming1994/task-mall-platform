<?php

namespace App\Modules\Refund\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Refund\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WechatRefundNotificationController extends Controller
{
    public function store(Request $request, RefundService $service): JsonResponse
    {
        $service->handleProviderNotification([
            'serial' => $request->header('Wechatpay-Serial'),
            'signature' => $request->header('Wechatpay-Signature'),
            'timestamp' => $request->header('Wechatpay-Timestamp'),
            'nonce' => $request->header('Wechatpay-Nonce'),
        ], $request->getContent());

        return response()->json(['code' => 'SUCCESS', 'message' => '成功']);
    }
}
