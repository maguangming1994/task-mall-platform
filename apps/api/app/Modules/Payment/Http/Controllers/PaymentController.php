<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order, PaymentService $service): JsonResponse
    {
        /** @var User $user */ $user = $request->user();
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');
        $scene = validator(['scene' => $request->header('X-Client-Platform')], ['scene' => ['required', 'in:wechat-mini,h5-wechat,h5-browser']])->validate()['scene'];
        $payment = $service->create($order, $user, $scene, $key);

        return ApiResponse::success($service->present($payment), 201);
    }

    public function index(Request $request, Order $order, PaymentService $service): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $items = Payment::query()->where('order_id', $order->id)->latest('id')->get()->map(fn ($payment) => $service->present($payment));

        return ApiResponse::success(['items' => $items]);
    }

    public function show(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        return ApiResponse::success($service->present($payment));
    }
}
