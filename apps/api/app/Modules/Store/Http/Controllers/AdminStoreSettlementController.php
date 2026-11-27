<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Middleware\RequirePlatformAdmin;
use App\Modules\Campus\Models\Campus;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreSettlement;
use App\Modules\Store\Services\StoreCampusService;
use App\Modules\Store\Services\StoreSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStoreSettlementController extends Controller
{
    public function index(Request $request, StoreSettlementService $service): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));
        $storeId = trim((string) $request->query('store_id', ''));
        $campusId = trim((string) $request->query('campus_id', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('page_size', 20)));

        $campusDbId = null;
        $contextCampus = $request->attributes->get('campus_database_id');
        if (is_int($contextCampus) && RequirePlatformAdmin::isCampusAgent($request->user())) {
            // 校区代理只能查看本校区批次，忽略传入的其他校区参数
            $campusDbId = $contextCampus;
        } elseif ($campusId !== '') {
            $campusDbId = Campus::query()->where('public_id', $campusId)->value('id');
        }

        $paginator = StoreSettlement::query()
            ->whereHas('store')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($campusDbId !== null, fn ($query) => $query->where('campus_id', $campusDbId))
            ->when($storeId !== '', fn ($query) => $query->whereHas('store', fn ($inner) => $inner->where('public_id', $storeId)))
            ->with(['store', 'campus'])
            ->latest('id')
            ->paginate($size, ['*'], 'page', $page);

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (StoreSettlement $settlement): array => $service->present($settlement))->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function preview(Request $request, Store $store, StoreSettlementService $service, StoreCampusService $campuses): JsonResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::success($service->preview($store, $data['period_start'], $data['period_end'], $campuses->resolve($store, $request->query('campus_id'))));
    }

    public function store(Request $request, Store $store, StoreSettlementService $service, StoreCampusService $campuses): JsonResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $campusId = $campuses->resolve($store, $request->input('campus_id'));
        $settlement = $service->create($store, $data['period_start'], $data['period_end'], $request->user()->id, $data['remark'] ?? null, $campusId);

        return ApiResponse::success($service->present($settlement), 201, '结算批次已生成。');
    }

    public function show(StoreSettlement $settlement, StoreSettlementService $service): JsonResponse
    {
        $this->assertStoreVisible($settlement);

        return ApiResponse::success($service->present($settlement, true));
    }

    public function confirm(StoreSettlement $settlement, StoreSettlementService $service): JsonResponse
    {
        $this->assertStoreVisible($settlement);

        return ApiResponse::success($service->present($service->confirm($settlement)), 200, '结算批次已确认。');
    }

    public function pay(StoreSettlement $settlement, StoreSettlementService $service): JsonResponse
    {
        $this->assertStoreVisible($settlement);

        return ApiResponse::success($service->present($service->markPaid($settlement)), 200, '已标记结算完成。');
    }

    private function assertStoreVisible(StoreSettlement $settlement): void
    {
        abort_unless(Store::query()->whereKey($settlement->store_id)->exists(), 404);
    }
}
