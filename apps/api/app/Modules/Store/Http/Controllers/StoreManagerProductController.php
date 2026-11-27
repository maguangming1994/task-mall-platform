<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Models\StoreProduct;
use App\Modules\Store\Services\StoreCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerProductController extends Controller
{
    use ResolvesStoreContext;

    public function index(Request $request, StoreCatalogService $catalog): JsonResponse
    {
        $store = $this->currentStore($request);
        $campusId = $this->currentCampusId($request, false);
        $status = trim((string) $request->query('status', ''));
        $keyword = trim((string) $request->query('keyword', ''));
        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('page_size', 20)));

        $paginator = StoreProduct::query()
            ->where('store_id', $store->id)
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($keyword !== '', fn ($query) => $query->where('name', 'like', "%{$keyword}%"))
            ->with(['category', 'skus.inventories'])
            ->latest('id')
            ->paginate($size, ['*'], 'page', $page);

        return ApiResponse::success([
            'items' => $paginator->getCollection()->map(fn (StoreProduct $product): array => $catalog->presentProduct($product, false, $campusId))->all(),
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function show(Request $request, StoreProduct $storeProduct, StoreCatalogService $catalog): JsonResponse
    {
        $this->assertProduct($request, $storeProduct);

        return ApiResponse::success($catalog->presentProduct($storeProduct, true, $this->currentCampusId($request, false)));
    }

    public function store(Request $request, StoreCatalogService $catalog): JsonResponse
    {
        $this->assertCanManage($request);
        $campusId = $this->currentCampusId($request);
        $data = $this->validateInput($request);
        $product = $catalog->create($this->currentStore($request), $request->user(), $data, $campusId);

        return ApiResponse::success($catalog->presentProduct($product, true, $campusId), 201, '商品已保存。');
    }

    public function update(Request $request, StoreProduct $storeProduct, StoreCatalogService $catalog): JsonResponse
    {
        $this->assertCanManage($request);
        $this->assertProduct($request, $storeProduct);
        $campusId = $this->currentCampusId($request);
        $data = $this->validateInput($request);
        $version = (int) $request->input('version', 0);
        $product = $catalog->update($storeProduct, $request->user(), $data, $version, $campusId);

        return ApiResponse::success($catalog->presentProduct($product, true, $campusId), 200, '商品已更新。');
    }

    public function status(Request $request, StoreProduct $storeProduct, StoreCatalogService $catalog): JsonResponse
    {
        $this->assertCanManage($request);
        $this->assertProduct($request, $storeProduct);
        $data = $request->validate([
            'action' => ['required', 'in:publish,off_shelf'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $product = $catalog->transition($storeProduct, $data['action'], $data['reason'] ?? null, $request->user());

        return ApiResponse::success($catalog->presentProduct($product, true, $this->currentCampusId($request, false)), 200, $data['action'] === 'publish' ? '商品已上架。' : '商品已下架。');
    }

    private function assertProduct(Request $request, StoreProduct $product): void
    {
        abort_unless((int) $product->store_id === (int) $this->currentStore($request)->id, 404);
    }

    /** @return array<string, mixed> */
    private function validateInput(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'cover_url' => ['sometimes', 'nullable', 'url:http,https', 'max:1500'],
            'gallery_images' => ['sometimes', 'array', 'max:9'],
            'gallery_images.*' => ['url:http,https', 'max:1500'],
            'category_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'status' => ['sometimes', 'in:draft,on_sale,off_shelf'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'skus' => ['required', 'array', 'min:1', 'max:30'],
            'skus.*.id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'skus.*.name' => ['required', 'string', 'max:160'],
            'skus.*.specs' => ['sometimes', 'array', 'max:10'],
            'skus.*.specs.*' => ['nullable', 'string', 'max:40'],
            'skus.*.image_url' => ['sometimes', 'nullable', 'url:http,https', 'max:1500'],
            'skus.*.fulfillment_type' => ['required', 'in:logistics,no_logistics'],
            'skus.*.weight_grams' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'skus.*.cash_amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'skus.*.limit_per_order' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'skus.*.stock_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'skus.*.status' => ['sometimes', 'in:active,inactive'],
        ]);
    }
}
