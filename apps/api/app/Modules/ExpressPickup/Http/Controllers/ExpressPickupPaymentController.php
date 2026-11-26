<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Services\ExpressPickupOrderService;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpressPickupPaymentController extends Controller
{
    public function store(
        Request $request,
        ExpressPickupOrder $order,
        ExpressPickupOrderService $orders,
        PaymentService $payments,
    ): JsonResponse {
        $data = $request->validate([
            'method' => ['required', 'in:wallet,wechat'],
            'scene' => ['nullable', 'string', 'max:32'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422, '缺少有效的 Idempotency-Key。');

        /** @var User $user */
        $user = $request->user();

        if ($data['method'] === 'wallet') {
            $paid = $orders->payWithWallet($user, $order, $key);

            return ApiResponse::success([
                'order' => $orders->present($paid, false),
                'payment' => null,
            ], 200, '钱包支付成功，已通知跑腿接单。');
        }

        $payment = $payments->createExpressPickupPayment($order, $user, $data['scene'] ?? 'wechat-mini', $key);

        return ApiResponse::success([
            'order' => $orders->present($order->fresh(), false),
            'payment' => $payments->present($payment),
        ], 201);
    }
}
