<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payment\Services\PaymentNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WechatPaymentNotificationController extends Controller
{
    public function store(Request $request, PaymentNotificationService $service): JsonResponse
    {
        $service->handle([
            'serial' => $request->header('Wechatpay-Serial'), 'signature' => $request->header('Wechatpay-Signature'),
            'timestamp' => $request->header('Wechatpay-Timestamp'), 'nonce' => $request->header('Wechatpay-Nonce'),
        ], $request->getContent());

        return response()->json(['code' => 'SUCCESS', 'message' => '成功']);
    }
}
