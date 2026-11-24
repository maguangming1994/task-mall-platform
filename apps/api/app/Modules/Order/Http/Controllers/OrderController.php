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
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:32'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = Order::query()
            ->where('user_id', $user->id)
            ->with(['batch', 'items', 'fulfillments'])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate(
                (int) ($filters['page_size'] ?? 20),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1),
            );

        $orders = collect($paginator->items())->map(fn ($order) => $service->presentOrder($order))->values();

        return ApiResponse::success([
            'items' => $orders,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
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
