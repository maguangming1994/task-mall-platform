<?php

namespace App\Modules\Product\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductCategory;
use App\Modules\Product\Models\ProductSku;
use App\Modules\Product\Services\ProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function categories(ProductCatalogService $catalog): JsonResponse
    {
        $items = ProductCategory::query()->with('parent')->withCount(['products', 'children'])
            ->orderBy('sort_order')->orderBy('id')->get()->map(fn (ProductCategory $item) => $this->categoryData($item));

        return ApiResponse::success(['items' => $items]);
    }

    public function storeCategory(Request $request, ProductCatalogService $catalog): JsonResponse
    {
        $data = $request->validate(['parent_id' => ['nullable', 'string', 'size:26', 'exists:product_categories,public_id'], 'name' => ['required', 'string', 'max:80'], 'slug' => ['required', 'alpha_dash', 'max:80', 'unique:product_categories,slug'], 'status' => ['sometimes', 'in:active,inactive'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);
        $category = $catalog->createCategory($data);

        return ApiResponse::success($this->categoryData($category->load('parent')->loadCount(['products', 'children'])), 201);
    }

    public function updateCategory(Request $request, ProductCategory $category): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('product_categories', 'slug')->ignore($category->id)],
            'status' => ['required', 'in:active,inactive'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        if (($data['parent_id'] ?? null) === $category->public_id) {
            throw ValidationException::withMessages(['parent_id' => '商品分类不能选择自身作为上级分类。']);
        }
        $parentId = filled($data['parent_id'] ?? null)
            ? ProductCategory::query()->where('public_id', $data['parent_id'])->value('id')
            : null;
        if (filled($data['parent_id'] ?? null) && ! $parentId) {
            throw ValidationException::withMessages(['parent_id' => '选择的上级分类不存在。']);
        }
        $ancestorId = $parentId;
        while ($ancestorId) {
            if ($ancestorId === $category->id) {
                throw ValidationException::withMessages(['parent_id' => '不能把当前分类的下级分类设为上级分类。']);
            }
            $ancestorId = ProductCategory::query()->whereKey($ancestorId)->value('parent_id');
        }
        $category->update([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'status' => $data['status'],
            'sort_order' => $data['sort_order'],
        ]);

        return ApiResponse::success($this->categoryData($category->fresh('parent')->loadCount(['products', 'children'])));
    }

    public function destroyCategory(ProductCategory $category): JsonResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            throw ValidationException::withMessages(['category' => '该分类仍有关联商品或下级分类，请先转移后再删除。']);
        }
        $category->delete();

        return response()->json(null, 204);
    }

    public function index(ProductCatalogService $catalog): JsonResponse
    {
        $items = Product::query()->latest('id')->get()->map(fn ($product) => $catalog->present($product));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function show(Product $product, ProductCatalogService $catalog): JsonResponse
    {
        return ApiResponse::success($catalog->present($product));
    }

    public function store(Request $request, ProductCatalogService $catalog, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $product = $catalog->create($admin, $this->definition($request), $this->key($request));
        $data = $catalog->present($product);
        $audit->record($admin, 'product.create', 'product', $product->public_id, null, $data);

        return ApiResponse::success($data, 201);
    }

    public function update(Request $request, Product $product, ProductCatalogService $catalog, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $catalog->present($product);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $updated = $catalog->update($admin, $product, $this->definition($request), $data['version']);
        $after = $catalog->present($updated);
        $audit->record($admin, 'product.update', 'product', $product->public_id, $before, $after);

        return ApiResponse::success($after);
    }

    public function status(Request $request, Product $product, ProductCatalogService $catalog, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:publish,off_shelf'], 'reason' => ['required', 'string', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $catalog->present($product);
        $updated = $catalog->transition($admin, $product, $data['action'], $data['reason'], $this->key($request));
        $after = $catalog->present($updated);
        $audit->record($admin, 'product.'.$data['action'], 'product', $product->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    public function updateOption(Request $request, Product $product, ProductSku $sku, string $purchaseMode, ProductCatalogService $catalog): JsonResponse
    {
        $data = $request->validate(['cash_amount' => ['required', 'integer', 'min:0'], 'points' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'], 'version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($catalog->present($catalog->updateOption($product, $sku, $purchaseMode, $data)));
    }

    private function definition(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'string', 'size:26', 'exists:product_categories,public_id'], 'name' => ['required', 'string', 'max:160'], 'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:1', 'max:200000'], 'cover_file_id' => ['nullable', 'string', 'size:26'],
            'gallery_images' => ['sometimes', 'array', 'min:1', 'max:9'],
            'gallery_images.*.url' => ['required', app()->environment('production') ? 'url:https' : 'url:http,https', 'max:2048'],
            'gallery_images.*.asset_id' => ['sometimes', 'nullable', 'string', 'size:26', 'exists:media_assets,public_id'],
            'gallery_images.*.alt' => ['sometimes', 'nullable', 'string', 'max:120'],
            'gallery_images.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:8'],
            'skus' => ['required', 'array', 'min:1', 'max:100'],
            'skus.*.sku_code' => ['required', 'string', 'max:80', 'distinct'], 'skus.*.name' => ['required', 'string', 'max:160'], 'skus.*.specs' => ['required', 'array'],
            'skus.*.image_file_public_id' => ['nullable', 'string', 'size:26'], 'skus.*.fulfillment_type' => ['required', 'in:logistics,no_logistics,virtual_delivery'],
            'skus.*.shipping_template_id' => ['nullable', 'string', 'size:26'], 'skus.*.weight_grams' => ['nullable', 'integer', 'min:1'],
            'skus.*.free_shipping' => ['sometimes', 'boolean'], 'skus.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'skus.*.limit_per_order' => ['nullable', 'integer', 'min:1'], 'skus.*.limit_per_day' => ['nullable', 'integer', 'min:1'],
            'skus.*.limit_campaign' => ['nullable', 'integer', 'min:1'], 'skus.*.limit_lifetime' => ['nullable', 'integer', 'min:1'],
            'skus.*.status' => ['sometimes', 'in:active,inactive'], 'skus.*.sort_order' => ['sometimes', 'integer', 'min:0'], 'skus.*.purchase_options' => ['required', 'array', 'min:1', 'max:3'],
            'skus.*.purchase_options.*.purchase_mode' => ['required', 'in:cash,points,cash_points'], 'skus.*.purchase_options.*.cash_amount' => ['required', 'integer', 'min:0'],
            'skus.*.purchase_options.*.points' => ['required', 'integer', 'min:0'], 'skus.*.purchase_options.*.enabled' => ['sometimes', 'boolean'],
            'skus.*.purchase_options.*.starts_at' => ['nullable', 'date'], 'skus.*.purchase_options.*.ends_at' => ['nullable', 'date'],
        ]);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }

    private function categoryData(ProductCategory $category): array
    {
        return [
            'id' => $category->public_id,
            'parent_id' => $category->parent?->public_id,
            'parent_name' => $category->parent?->name,
            'name' => $category->name,
            'slug' => $category->slug,
            'status' => $category->status,
            'sort_order' => (int) $category->sort_order,
            'product_count' => (int) ($category->products_count ?? $category->products()->count()),
            'children_count' => (int) ($category->children_count ?? $category->children()->count()),
        ];
    }
}
