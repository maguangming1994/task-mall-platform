<?php

namespace App\Modules\Order\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderBatch;
use App\Modules\Order\Services\CheckoutOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request, CheckoutOrderService $service): JsonResponse
    {
        /** @var User $user */ $user = $request->user();
        $orders = Order::query()->where('user_id', $user->id)->with(['batch', 'items', 'fulfillments'])->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->latest('id')->get()->map(fn ($order) => $service->presentOrder($order));

        return ApiResponse::success(['items' => $orders, 'pagination' => ['page' => 1, 'page_size' => $orders->count(), 'total' => $orders->count()]]);
    }

    public function show(Request $request, Order $order, CheckoutOrderService $service): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return ApiResponse::success($service->presentOrder($order));
    }

    public function batch(Request $request, OrderBatch $orderBatch, CheckoutOrderService $service): JsonResponse
    {
        abort_unless($orderBatch->user_id === $request->user()->id, 404);

        return ApiResponse::success($service->presentBatch($orderBatch));
    }
}
