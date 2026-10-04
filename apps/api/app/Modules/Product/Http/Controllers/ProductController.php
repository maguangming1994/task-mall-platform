<?php

namespace App\Modules\Product\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductCategory;
use App\Modules\Product\Services\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function categories(): JsonResponse
    {
        $items = ProductCategory::query()->where('status', 'active')
            ->whereHas('products', fn ($query) => $query->where('status', 'on_sale'))
            ->withCount(['products' => fn ($query) => $query->where('status', 'on_sale')])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ProductCategory $category) => [
                'id' => $category->public_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'sort_order' => (int) $category->sort_order,
                'product_count' => (int) $category->products_count,
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function index(Request $request, ProductCatalogService $catalog): JsonResponse
    {
        $keyword = trim((string) $request->string('keyword'));
        $products = Product::query()->where('status', 'on_sale')->with(['category', 'skus.purchaseOptions'])
            ->whereHas('category', fn ($category) => $category->where('status', 'active'))
            ->when($request->string('category_id')->isNotEmpty(), fn ($query) => $query->whereHas('category', fn ($category) => $category->where('public_id', $request->string('category_id'))->where('status', 'active')))
            ->when($keyword !== '', fn ($query) => $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('subtitle', 'like', "%{$keyword}%");
            }))
            ->latest('published_at')->get()->map(fn ($product) => $catalog->present($product, true))->filter(fn ($product) => count($product['skus']) > 0)->values();

        return ApiResponse::success(['items' => $products, 'pagination' => ['page' => 1, 'page_size' => $products->count(), 'total' => $products->count()]]);
    }

    public function show(Product $product, ProductCatalogService $catalog): JsonResponse
    {
        $product->loadMissing('category');
        abort_unless($product->status === 'on_sale' && $product->category?->status === 'active', 404);
        $data = $catalog->present($product, true);
        abort_if(count($data['skus']) === 0, 404);

        return ApiResponse::success($data);
    }
}
