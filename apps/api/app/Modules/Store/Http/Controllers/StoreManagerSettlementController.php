<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Models\StoreSettlement;
use App\Modules\Store\Services\StoreSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerSettlementController extends Controller
{
    use ResolvesStoreContext;

    public function index(Request $request, StoreSettlementService $service): JsonResponse
    {
        $store = $this->currentStore($request);
        $campusId = $this->currentCampusId($request, false);
        $status = trim((string) $request->query('status', ''));
        $paginator = StoreSettlement::query()
            ->where('store_id', $store->id)
            ->where('campus_id', $campusId)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(min(50, max(1, (int) $request->query('page_size', 20))))
            ->through(fn (StoreSettlement $settlement): array => $service->present($settlement));

        return ApiResponse::success([
            'items' => $paginator->items(),
            'unbilled' => $service->unbilled($store, $campusId),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function show(Request $request, StoreSettlement $settlement, StoreSettlementService $service): JsonResponse
    {
        abort_unless((int) $settlement->store_id === (int) $this->currentStore($request)->id, 404);

        return ApiResponse::success($service->present($settlement, true));
    }
}
