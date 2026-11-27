<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Models\StoreCategory;
use App\Modules\Store\Models\StoreProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerCategoryController extends Controller
{
    use ResolvesStoreContext;

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $items = StoreCategory::query()
            ->where('store_id', $store->id)
            ->withCount(['products' => fn ($query) => $query->where('status', StoreProduct::STATUS_ON_SALE)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (StoreCategory $category): array => [
                'id' => $category->public_id,
                'name' => $category->name,
                'sort_order' => (int) $category->sort_order,
                'product_count' => (int) $category->products_count,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
        $category = StoreCategory::query()->create([
            'store_id' => $this->currentStore($request)->id,
            'name' => trim($data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return ApiResponse::success(['id' => $category->public_id, 'name' => $category->name, 'sort_order' => (int) $category->sort_order], 201);
    }

    public function update(Request $request, StoreCategory $storeCategory): JsonResponse
    {
        $this->assertCanManage($request);
        abort_unless((int) $storeCategory->store_id === (int) $this->currentStore($request)->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
        $storeCategory->update(['name' => trim($data['name']), 'sort_order' => (int) ($data['sort_order'] ?? $storeCategory->sort_order)]);

        return ApiResponse::success(['id' => $storeCategory->public_id, 'name' => $storeCategory->name, 'sort_order' => (int) $storeCategory->sort_order]);
    }

    public function destroy(Request $request, StoreCategory $storeCategory): JsonResponse
    {
        $this->assertCanManage($request);
        abort_unless((int) $storeCategory->store_id === (int) $this->currentStore($request)->id, 404);
        $storeCategory->delete();

        return ApiResponse::success(null);
    }
}
