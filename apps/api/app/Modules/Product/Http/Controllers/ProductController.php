<?php

namespace App\Modules\Product\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Services\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, ProductCatalogService $catalog): JsonResponse
    {
        $products = Product::query()->where('status', 'on_sale')->with(['category', 'skus.purchaseOptions'])
            ->when($request->string('category_id')->isNotEmpty(), fn ($query) => $query->whereHas('category', fn ($category) => $category->where('public_id', $request->string('category_id'))))
            ->latest('published_at')->get()->map(fn ($product) => $catalog->present($product, true))->filter(fn ($product) => count($product['skus']) > 0)->values();

        return ApiResponse::success(['items' => $products, 'pagination' => ['page' => 1, 'page_size' => $products->count(), 'total' => $products->count()]]);
    }

    public function show(Product $product, ProductCatalogService $catalog): JsonResponse
    {
        abort_unless($product->status === 'on_sale', 404);
        $data = $catalog->present($product, true);
        abort_if(count($data['skus']) === 0, 404);

        return ApiResponse::success($data);
    }
}
