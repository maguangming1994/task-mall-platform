<?php

namespace App\Modules\Product\Services;

use App\Common\Support\RichTextSanitizer;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\ShippingTemplate;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductCategory;
use App\Modules\Product\Models\ProductSku;
use App\Modules\Product\Models\ProductVersion;
use App\Modules\Product\Models\SkuPurchaseOption;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCatalogService
{
    private const CHANNELS = ['wechat_mini', 'wechat_h5', 'web_h5'];

    public function __construct(private readonly RichTextSanitizer $richText) {}

    public function createCategory(array $data): ProductCategory
    {
        return ProductCategory::query()->create([
            ...$data,
            'parent_id' => isset($data['parent_id']) ? ProductCategory::query()->where('public_id', $data['parent_id'])->value('id') : null,
        ]);
    }

    public function create(AdminUser $admin, array $data, string $idempotencyKey): Product
    {
        $data['description'] = $this->richText->sanitize((string) $data['description']);
        if ($this->richText->toPlainText($data['description']) === '' && ! str_contains($data['description'], '<img')) {
            throw ValidationException::withMessages(['description' => '商品详情不能只包含无效或不安全内容。']);
        }
        $hash = hash('sha256', $idempotencyKey);
        $existing = Product::query()->where('created_by_admin_id', $admin->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($admin, $data, $hash) {
            $this->validateDefinition($data);
            $product = Product::query()->create([
                'product_category_id' => $this->categoryId($data['category_id'] ?? null),
                'name' => $data['name'], 'subtitle' => $data['subtitle'] ?? null, 'description' => $data['description'],
                'cover_file_public_id' => $data['cover_file_id'] ?? null, 'gallery_images' => $data['gallery_images'] ?? [], 'created_by_admin_id' => $admin->id,
                'idempotency_key_hash' => $hash,
            ]);
            $this->replaceSkus($product, $data['skus']);
            $this->captureVersion($product, $admin, ['initial']);

            return $product->fresh(['category', 'skus.purchaseOptions']);
        });
    }

    public function update(AdminUser $admin, Product $product, array $data, int $expectedVersion): Product
    {
        $data['description'] = $this->richText->sanitize((string) $data['description']);
        if ($this->richText->toPlainText($data['description']) === '' && ! str_contains($data['description'], '<img')) {
            throw ValidationException::withMessages(['description' => '商品详情不能只包含无效或不安全内容。']);
        }

        return DB::transaction(function () use ($admin, $product, $data, $expectedVersion) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            if ($product->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => '商品已被其他管理员修改，请刷新后重试。']);
            }
            $this->validateDefinition($data);
            $before = $this->snapshot($product);
            $product->update([
                'product_category_id' => $this->categoryId($data['category_id'] ?? null),
                'name' => $data['name'], 'subtitle' => $data['subtitle'] ?? null, 'description' => $data['description'],
                'cover_file_public_id' => $data['cover_file_id'] ?? null, 'gallery_images' => $data['gallery_images'] ?? $product->gallery_images ?? [],
                'current_version_no' => $product->current_version_no + 1, 'lock_version' => $product->lock_version + 1,
            ]);
            $this->syncSkus($product, $data['skus']);
            $after = $this->snapshot($product->fresh(['category', 'skus.purchaseOptions']));
            $changed = array_values(array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH)));
            $this->captureVersion($product, $admin, $changed);

            return $product->fresh(['category', 'skus.purchaseOptions']);
        });
    }

    public function transition(AdminUser $admin, Product $product, string $action, string $reason, string $idempotencyKey): Product
    {
        $hash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($admin, $product, $action, $reason, $hash) {
            $existing = DB::table('product_status_changes')->where('idempotency_key_hash', $hash)->first();
            if ($existing) {
                return Product::query()->findOrFail($existing->product_id);
            }
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $to = match ([$product->status, $action]) {
                ['draft', 'publish'], ['off_shelf', 'publish'] => 'on_sale',
                ['on_sale', 'off_shelf'] => 'off_shelf',
                default => throw ValidationException::withMessages(['action' => '当前商品状态不允许此操作。']),
            };
            if ($to === 'on_sale') {
                $this->assertPublishable($product);
            }
            DB::table('product_status_changes')->insert([
                'product_id' => $product->id, 'from_status' => $product->status, 'to_status' => $to,
                'action' => $action, 'reason' => $reason, 'admin_user_id' => $admin->id,
                'idempotency_key_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $product->update([
                'status' => $to, 'published_at' => $to === 'on_sale' ? now() : $product->published_at,
                'off_shelf_at' => $to === 'off_shelf' ? now() : null, 'lock_version' => $product->lock_version + 1,
            ]);

            return $product->fresh(['category', 'skus.purchaseOptions']);
        });
    }

    public function updateOption(Product $product, ProductSku $sku, string $mode, array $data): Product
    {
        return DB::transaction(function () use ($product, $sku, $mode, $data) {
            if ($sku->product_id !== $product->id) {
                abort(404);
            }
            $this->validateOption(['purchase_mode' => $mode, ...$data]);
            $option = $sku->purchaseOptions()->where('purchase_mode', $mode)->lockForUpdate()->firstOrFail();
            if ($option->lock_version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => '购买方案已更新，请刷新后重试。']);
            }
            $option->update([
                'cash_amount' => $data['cash_amount'], 'points' => $data['points'], 'enabled' => $data['enabled'],
                'channels' => self::CHANNELS, 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
                'lock_version' => $option->lock_version + 1,
            ]);
            if (! $sku->purchaseOptions()->where('enabled', true)->exists()) {
                throw ValidationException::withMessages(['enabled' => '同一 SKU 至少需要保留一种购买方式。']);
            }

            return $product->fresh(['category', 'skus.purchaseOptions']);
        });
    }

    public function present(Product $product, bool $public = false): array
    {
        $product->loadMissing(['category', 'skus.purchaseOptions']);
        $skus = $product->skus
            ->when($public, fn ($items) => $items->where('status', 'active'))
            ->map(function (ProductSku $sku) use ($public) {
                $options = $sku->purchaseOptions->filter(function (SkuPurchaseOption $option) use ($public) {
                    if (! $public) {
                        return true;
                    }

                    return $option->enabled
                        && (! $option->starts_at || $option->starts_at->lte(now()))
                        && (! $option->ends_at || $option->ends_at->gt(now()));
                })->values()->map(fn (SkuPurchaseOption $option) => [
                    'id' => $option->public_id, 'purchase_mode' => $option->purchase_mode,
                    'cash_amount' => $option->cash_amount, 'points' => $option->points, 'enabled' => $option->enabled,
                    'channels' => $option->channels, 'starts_at' => $option->starts_at?->toIso8601String(),
                    'ends_at' => $option->ends_at?->toIso8601String(), 'version' => $option->lock_version,
                ]);

                return [
                    'id' => $sku->public_id, 'sku_code' => $sku->sku_code, 'name' => $sku->name,
                    'specs' => $sku->specs, 'image_file_id' => $sku->image_file_public_id,
                    'fulfillment_type' => $sku->fulfillment_type, 'status' => $sku->status,
                    'shipping_template_id' => $sku->shippingTemplate?->public_id, 'weight_grams' => $sku->weight_grams,
                    'free_shipping' => $sku->free_shipping, 'low_stock_threshold' => $sku->low_stock_threshold,
                    'purchase_limits' => ['per_order' => $sku->limit_per_order, 'per_day' => $sku->limit_per_day, 'campaign' => $sku->limit_campaign, 'lifetime' => $sku->limit_lifetime],
                    'stock' => max(0, (int) (($sku->inventory?->on_hand_quantity ?? 0) - ($sku->inventory?->reserved_quantity ?? 0))),
                    'purchase_options' => $options,
                ];
            })->filter(fn ($sku) => ! $public || count($sku['purchase_options']) > 0)->values();

        return [
            'id' => $product->public_id, 'name' => $product->name, 'subtitle' => $product->subtitle,
            'description' => $product->description, 'description_text' => $this->richText->toPlainText($product->description),
            'description_format' => 'rich_text_html', 'description_schema_version' => 1, 'cover_file_id' => $product->cover_file_public_id,
            'gallery_images' => $this->presentGallery($product->gallery_images ?? []),
            'category' => $product->category ? ['id' => $product->category->public_id, 'name' => $product->category->name] : null,
            'status' => $product->status, 'version' => $product->lock_version, 'definition_version' => $product->current_version_no,
            'published_at' => $product->published_at?->toIso8601String(), 'skus' => $skus,
        ];
    }

    /** @param array<int,array<string,mixed>> $images */
    private function presentGallery(array $images): array
    {
        $assetUrls = MediaAsset::query()
            ->whereIn('public_id', collect($images)->pluck('asset_id')->filter()->all())
            ->where('status', 'active')
            ->pluck('public_url', 'public_id');

        return collect($images)->sortBy('sort_order')->values()->map(function (array $image) use ($assetUrls): array {
            if (filled($image['asset_id'] ?? null) && $assetUrls->has($image['asset_id'])) {
                $image['url'] = $assetUrls->get($image['asset_id']);
            }

            return $image;
        })->all();
    }

    private function validateDefinition(array $data): void
    {
        foreach ($data['skus'] as $skuIndex => $sku) {
            $modes = array_column($sku['purchase_options'], 'purchase_mode');
            if (count($modes) !== count(array_unique($modes))) {
                throw ValidationException::withMessages(["skus.$skuIndex.purchase_options" => '同一 SKU 的购买方式不能重复。']);
            }
            foreach ($sku['purchase_options'] as $option) {
                $this->validateOption($option);
            }
        }
    }

    private function validateOption(array $option): void
    {
        $cash = (int) $option['cash_amount'];
        $points = (int) $option['points'];
        $valid = match ($option['purchase_mode']) {
            'cash' => $cash > 0 && $points === 0,
            'points' => $cash === 0 && $points > 0,
            'cash_points' => $cash > 0 && $points > 0,
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['purchase_options' => '购买方式与现金、积分金额组合不匹配。']);
        }
        if (isset($option['starts_at'], $option['ends_at']) && $option['starts_at'] >= $option['ends_at']) {
            throw ValidationException::withMessages(['purchase_options' => '购买方案结束时间必须晚于开始时间。']);
        }
    }

    private function replaceSkus(Product $product, array $skus): void
    {
        foreach ($skus as $skuData) {
            $templateId = isset($skuData['shipping_template_id']) ? ShippingTemplate::query()->where('public_id', $skuData['shipping_template_id'])->value('id') : null;
            $sku = $product->skus()->create([
                ...Arr::only($skuData, ['sku_code', 'name', 'specs', 'image_file_public_id', 'fulfillment_type', 'weight_grams', 'free_shipping', 'low_stock_threshold', 'limit_per_order', 'limit_per_day', 'limit_campaign', 'limit_lifetime', 'status', 'sort_order']),
                'shipping_template_id' => $templateId,
            ]);
            Inventory::query()->create(['product_sku_id' => $sku->id]);
            foreach ($skuData['purchase_options'] as $option) {
                $sku->purchaseOptions()->create([
                    ...Arr::only($option, ['purchase_mode', 'cash_amount', 'points', 'enabled', 'starts_at', 'ends_at']),
                    'channels' => self::CHANNELS,
                ]);
            }
        }
    }

    private function syncSkus(Product $product, array $skus): void
    {
        $submittedCodes = [];
        foreach ($skus as $skuData) {
            $submittedCodes[] = $skuData['sku_code'];
            $templateId = isset($skuData['shipping_template_id']) ? ShippingTemplate::query()->where('public_id', $skuData['shipping_template_id'])->value('id') : null;
            $attributes = [
                ...Arr::only($skuData, ['sku_code', 'name', 'specs', 'image_file_public_id', 'fulfillment_type', 'weight_grams', 'free_shipping', 'low_stock_threshold', 'limit_per_order', 'limit_per_day', 'limit_campaign', 'limit_lifetime', 'sort_order']),
                'status' => $skuData['status'] ?? 'active',
                'shipping_template_id' => $templateId,
            ];
            $sku = $product->skus()->where('sku_code', $skuData['sku_code'])->first();
            if ($sku) {
                $sku->update([...$attributes, 'lock_version' => $sku->lock_version + 1]);
            } else {
                $sku = $product->skus()->create($attributes);
                Inventory::query()->create(['product_sku_id' => $sku->id]);
            }

            $submittedModes = [];
            foreach ($skuData['purchase_options'] as $optionData) {
                $submittedModes[] = $optionData['purchase_mode'];
                $option = $sku->purchaseOptions()->where('purchase_mode', $optionData['purchase_mode'])->first();
                $optionAttributes = [
                    ...Arr::only($optionData, ['purchase_mode', 'cash_amount', 'points', 'enabled', 'starts_at', 'ends_at']),
                    'channels' => self::CHANNELS,
                ];
                if ($option) {
                    $option->update([...$optionAttributes, 'lock_version' => $option->lock_version + 1]);
                } else {
                    $sku->purchaseOptions()->create($optionAttributes);
                }
            }
            $sku->purchaseOptions()->whereNotIn('purchase_mode', $submittedModes)->update(['enabled' => false, 'updated_at' => now()]);
        }

        $product->skus()->whereNotIn('sku_code', $submittedCodes)->update(['status' => 'inactive', 'updated_at' => now()]);
    }

    private function assertPublishable(Product $product): void
    {
        $product->load('skus.purchaseOptions');
        if ($product->skus->isEmpty() || $product->skus->contains(fn ($sku) => $sku->status === 'active' && ! $sku->purchaseOptions->contains('enabled', true))) {
            throw ValidationException::withMessages(['skus' => '每个启用 SKU 至少需要一种已启用购买方式。']);
        }
        foreach ($product->skus->where('status', 'active')->where('fulfillment_type', 'logistics') as $sku) {
            if (! $sku->shipping_template_id || $sku->shippingTemplate?->status !== 'active') {
                throw ValidationException::withMessages(['skus' => '实体物流 SKU 必须绑定有效运费模板。']);
            }
            $content = $sku->shippingTemplate->versions()->where('version_no', $sku->shippingTemplate->current_version_no)->value('content');
            if (($content['charge_type'] ?? null) === 'by_weight' && ! $sku->weight_grams) {
                throw ValidationException::withMessages(['skus' => '按重量计费的 SKU 必须配置计费重量。']);
            }
        }
    }

    private function captureVersion(Product $product, AdminUser $admin, array $changedFields): void
    {
        ProductVersion::query()->create(['product_id' => $product->id, 'version_no' => $product->current_version_no, 'snapshot' => $this->snapshot($product), 'changed_fields' => $changedFields, 'created_by_admin_id' => $admin->id]);
    }

    private function snapshot(Product $product): array
    {
        return Arr::except($this->present($product->fresh(['category', 'skus.purchaseOptions'])), ['status', 'version', 'published_at']);
    }

    private function categoryId(?string $publicId): ?int
    {
        return $publicId ? ProductCategory::query()->where('public_id', $publicId)->firstOrFail()->id : null;
    }
}
