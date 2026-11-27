<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Order\Models\Order;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Services\StoreCampusService;
use App\Modules\Store\Services\StoreOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerOrderController extends Controller
{
    use ResolvesStoreContext;

    public function index(Request $request, StoreOrderService $service): JsonResponse
    {
        $paginator = $service->paginate($this->currentStore($request), [
            'status' => $request->query('status'),
            'keyword' => $request->query('keyword'),
            'page' => $request->query('page'),
            'page_size' => $request->query('page_size'),
        ], $this->currentCampusId($request, false));

        return ApiResponse::success([
            'items' => $paginator->items(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function show(Request $request, string $order, StoreOrderService $service, StoreCampusService $campuses): JsonResponse
    {
        $store = $this->currentStore($request);
        $campusId = $this->currentCampusId($request, false);
        // 显式按门店与校区定位，避免订单全局校区作用域跟随店主个人校区
        $model = Order::query()
            ->withoutGlobalScope('campus_context')
            ->where('public_id', $order)
            ->whereHas('items', fn ($query) => $query->where('store_id', $store->id))
            ->when($campusId !== null, fn ($query) => $campuses->applyOrderCampus($query, $store, $campusId))
            ->firstOrFail();
        $model->load(['items' => fn ($query) => $query->where('store_id', $store->id)]);

        return ApiResponse::success($service->present($model));
    }

    public function overview(Request $request, StoreOrderService $service): JsonResponse
    {
        return ApiResponse::success($service->overview($this->currentStore($request), $this->currentCampusId($request, false)));
    }
}
