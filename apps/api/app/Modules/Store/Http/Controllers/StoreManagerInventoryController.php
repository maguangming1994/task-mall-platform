<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Models\StoreProductSku;
use App\Modules\Store\Services\StoreInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerInventoryController extends Controller
{
    use ResolvesStoreContext;

    public function index(Request $request, StoreInventoryService $service): JsonResponse
    {
        $store = $this->currentStore($request);
        $campusId = $this->currentCampusId($request);
        $keyword = trim((string) $request->query('keyword', ''));
        $lowStockOnly = $request->boolean('low_stock');

        // 以规格为主维度列出，某校区尚未建库存行时按零库存展示，便于门店直接补货
        $items = StoreProductSku::query()
            ->whereHas('product', fn ($query) => $query->where('store_id', $store->id))
            ->with(['product', 'inventories' => fn ($query) => $query->where('campus_id', $campusId)])
            ->when($keyword !== '', fn ($query) => $query->where('name', 'like', "%{$keyword}%"))
            ->when($lowStockOnly, fn ($query) => $query->where(function ($inner) use ($campusId): void {
                $inner->whereDoesntHave('inventories', fn ($sub) => $sub->where('campus_id', $campusId))
                    ->orWhereHas('inventories', fn ($sub) => $sub->where('campus_id', $campusId)->whereColumn('on_hand_quantity', '<=', 'reserved_quantity'));
            }))
            ->orderBy('id')
            ->paginate(min(100, max(1, (int) $request->query('page_size', 50))))
            ->through(fn (StoreProductSku $sku): array => $service->presentForCampus($sku, $sku->inventoryForCampus($campusId)));

        return ApiResponse::success([
            'items' => $items->items(),
            'pagination' => ['page' => $items->currentPage(), 'page_size' => $items->perPage(), 'total' => $items->total(), 'last_page' => $items->lastPage()],
        ]);
    }

    public function adjust(Request $request, StoreProductSku $storeSku, StoreInventoryService $service): JsonResponse
    {
        $this->assertCanManage($request);
        $service->assertOwner($this->currentStore($request), $storeSku);
        $campusId = $this->currentCampusId($request);
        $data = $request->validate([
            'action' => ['required', 'in:increase,decrease,set'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if (strlen($key) < 12) {
            $key = 'store-inventory:'.$storeSku->public_id.':'.$campusId.':'.$data['action'].':'.$data['quantity'].':'.$data['version'];
        }
        $inventory = $service->adjust(
            $storeSku,
            $campusId,
            $data['action'],
            (int) $data['quantity'],
            (int) $data['version'],
            (string) ($data['reason'] ?? '门店工作台手动调整库存。'),
            $key,
        );

        return ApiResponse::success($service->presentForCampus($storeSku, $inventory), 200, '库存已更新。');
    }
}
