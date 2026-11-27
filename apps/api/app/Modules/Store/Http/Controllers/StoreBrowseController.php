<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreCategory;
use App\Modules\Store\Models\StoreProduct;
use App\Modules\Store\Models\StoreProductSku;
use App\Modules\Store\Services\StoreCampusService;
use App\Modules\Store\Services\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreBrowseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);

        $paginator = Store::query()
            ->where('status', Store::STATUS_ACTIVE)
            ->when($keyword !== '', fn ($query) => $query->where(function ($inner) use ($keyword): void {
                $inner->where('name', 'like', "%{$keyword}%")->orWhere('description', 'like', "%{$keyword}%");
            }))
            ->withCount(['products' => fn ($query) => $query->where('status', StoreProduct::STATUS_ON_SALE)])
            ->orderByDesc('id')
            ->paginate($size, ['*'], 'page', $page);

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (Store $store): array => $this->presentStore($store))->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function show(Store $store, StoreCampusService $campuses): JsonResponse
    {
        abort_unless($store->status === Store::STATUS_ACTIVE, 404);
        $campusId = $campuses->contextCampusId($store);
        $store->load(['categories' => fn ($query) => $query->where('status', 'active')->orderBy('sort_order')->orderBy('id')]);
        $products = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', StoreProduct::STATUS_ON_SALE)
            ->with(['category', 'skus.inventories' => fn ($query) => $query->where('campus_id', $campusId)])
            ->latest('published_at')
            ->limit(20)
            ->get();

        return ApiResponse::success([
            ...$this->presentStore($store),
            'categories' => $store->categories->map(fn (StoreCategory $category): array => [
                'id' => $category->public_id,
                'name' => $category->name,
            ])->all(),
            'products' => $products->map(fn (StoreProduct $product): array => $this->presentProductCard($product, $campusId))->all(),
        ]);
    }

    public function products(Request $request, Store $store, StoreCampusService $campuses): JsonResponse
    {
        abort_unless($store->status === Store::STATUS_ACTIVE, 404);
        $campusId = $campuses->contextCampusId($store);
        $data = $request->validate([
            'category_id' => ['nullable', 'string', 'max:26'],
            'keyword' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);

        $paginator = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', StoreProduct::STATUS_ON_SALE)
            ->when(! empty($data['category_id']), fn ($query) => $query->whereHas('category', fn ($inner) => $inner->where('public_id', $data['category_id'])))
            ->when($keyword !== '', fn ($query) => $query->where(function ($inner) use ($keyword): void {
                $inner->where('name', 'like', "%{$keyword}%")->orWhere('subtitle', 'like', "%{$keyword}%");
            }))
            ->with(['category', 'skus.inventories' => fn ($query) => $query->where('campus_id', $campusId)])
            ->latest('published_at')
            ->paginate($size, ['*'], 'page', $page);

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (StoreProduct $product): array => $this->presentProductCard($product, $campusId))->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function product(StoreProduct $storeProduct, StoreCatalogService $catalog): JsonResponse
    {
        abort_unless($storeProduct->status === StoreProduct::STATUS_ON_SALE, 404);
        $storeProduct->loadMissing('store');
        abort_unless($storeProduct->store?->status === Store::STATUS_ACTIVE, 404);

        return ApiResponse::success($catalog->presentProduct($storeProduct));
    }

    /** @return array<string, mixed> */
    private function presentStore(Store $store): array
    {
        return [
            'id' => $store->public_id,
            'name' => $store->name,
            'logo_url' => $store->logo_url,
            'description' => $store->description,
            'contact_phone' => $store->contact_phone,
            'address' => $store->address,
            'business_hours' => $store->business_hours,
            'product_count' => (int) ($store->products_count ?? $store->products()->where('status', StoreProduct::STATUS_ON_SALE)->count()),
            'created_at' => $store->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentProductCard(StoreProduct $product, ?int $campusId): array
    {
        $skus = $product->skus->where('status', 'active')->values();
        $prices = $skus->pluck('cash_amount')->filter(fn ($amount) => (int) $amount > 0);

        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'subtitle' => $product->subtitle,
            'cover_url' => $product->cover_url,
            'category' => $product->category === null ? null : ['id' => $product->category->public_id, 'name' => $product->category->name],
            'price_from' => $prices->isEmpty() ? null : (int) $prices->min(),
            'price_to' => $prices->isEmpty() ? null : (int) $prices->max(),
            'sku_count' => $skus->count(),
            'available_quantity' => $skus->sum(function (StoreProductSku $sku) use ($campusId): int {
                $inventory = $sku->inventoryForCampus($campusId);

                return $inventory === null ? 0 : $inventory->available();
            }),
        ];
    }
}
