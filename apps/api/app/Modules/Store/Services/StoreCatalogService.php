<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreCategory;
use App\Modules\Store\Models\StoreInventory;
use App\Modules\Store\Models\StoreInventoryTransaction;
use App\Modules\Store\Models\StoreProduct;
use App\Modules\Store\Models\StoreProductSku;
use App\Modules\Store\Models\StoreProductStatusChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreCatalogService
{
    public function __construct(private readonly StoreCampusService $campuses) {}

    /** @return array<string, mixed> */
    public function presentProduct(StoreProduct $product, bool $detail = true, ?int $campusId = null): array
    {
        $product->loadMissing(['store', 'category', 'skus.inventories']);
        $campusId ??= $product->store === null ? null : $this->campuses->contextCampusId($product->store);

        return [
            'id' => $product->public_id,
            'store' => $product->store === null ? null : [
                'id' => $product->store->public_id,
                'name' => $product->store->name,
                'logo_url' => $product->store->logo_url,
                'contact_phone' => $product->store->contact_phone,
                'address' => $product->store->address,
                'business_hours' => $product->store->business_hours,
            ],
            'category' => $product->category === null ? null : ['id' => $product->category->public_id, 'name' => $product->category->name],
            'name' => $product->name,
            'subtitle' => $product->subtitle,
            'description' => $detail ? $product->description : null,
            'cover_url' => $product->cover_url,
            'gallery_images' => $product->gallery_images ?? [],
            'status' => $product->status,
            'admin_off_shelf_reason' => $product->admin_off_shelf_reason,
            'version' => (int) $product->lock_version,
            'published_at' => $product->published_at?->toIso8601String(),
            'off_shelf_at' => $product->off_shelf_at?->toIso8601String(),
            'created_at' => $product->created_at?->toIso8601String(),
            'skus' => $product->skus->map(fn (StoreProductSku $sku): array => $this->presentSku($sku, $campusId))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentSku(StoreProductSku $sku, ?int $campusId = null): array
    {
        $inventory = $sku->inventoryForCampus($campusId);
        $available = $inventory === null ? 0 : max(0, (int) $inventory->on_hand_quantity - (int) $inventory->reserved_quantity);

        return [
            'id' => $sku->public_id,
            'name' => $sku->name,
            'specs' => $sku->specs ?? [],
            'image_url' => $sku->image_url,
            'fulfillment_type' => $sku->fulfillment_type,
            'weight_grams' => $sku->weight_grams,
            'cash_amount' => (int) $sku->cash_amount,
            'limit_per_order' => $sku->limit_per_order,
            'status' => $sku->status,
            'version' => (int) $sku->lock_version,
            'available_quantity' => $available,
            'on_hand_quantity' => $inventory === null ? 0 : (int) $inventory->on_hand_quantity,
            'reserved_quantity' => $inventory === null ? 0 : (int) $inventory->reserved_quantity,
            'inventory_version' => $inventory === null ? 1 : (int) $inventory->version,
        ];
    }

    public function create(Store $store, User $user, array $data, ?int $campusId = null): StoreProduct
    {
        $this->assertStoreActive($store);
        $this->validateDefinition($data);
        $campusId ??= $store->campus_id === null ? null : (int) $store->campus_id;
        if (($data['status'] ?? StoreProduct::STATUS_DRAFT) === StoreProduct::STATUS_ON_SALE && count($data['skus']) === 0) {
            throw ValidationException::withMessages(['skus' => ['上架商品至少需要一个规格。']]);
        }

        return DB::transaction(function () use ($store, $user, $data, $campusId): StoreProduct {
            $product = StoreProduct::query()->create([
                'store_id' => $store->id,
                'store_category_id' => $this->categoryId($store, $data['category_id'] ?? null),
                'campus_id' => $store->campus_id,
                'name' => trim((string) $data['name']),
                'subtitle' => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'cover_url' => $data['cover_url'] ?? null,
                'gallery_images' => array_values($data['gallery_images'] ?? []),
                'status' => StoreProduct::STATUS_DRAFT,
                'created_by_user_id' => $user->id,
            ]);
            $this->syncSkus($product, $data['skus'], $campusId);
            $status = (string) ($data['status'] ?? StoreProduct::STATUS_DRAFT);
            if ($status === StoreProduct::STATUS_ON_SALE) {
                $this->transition($product, 'publish', null, $user);
            }

            return $product->fresh(['store', 'category', 'skus.inventories']);
        });
    }

    public function update(StoreProduct $product, User $user, array $data, int $expectedVersion, ?int $campusId = null): StoreProduct
    {
        $this->validateDefinition($data);
        $campusId ??= $product->store?->campus_id === null ? null : (int) $product->store->campus_id;

        return DB::transaction(function () use ($product, $user, $data, $expectedVersion, $campusId): StoreProduct {
            $locked = StoreProduct::query()->lockForUpdate()->findOrFail($product->id);
            $this->assertStoreActive($locked->store);
            if ((int) $locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => ['商品已被其他成员修改，请刷新后重试。']]);
            }
            $locked->update([
                'store_category_id' => $this->categoryId($locked->store, $data['category_id'] ?? null),
                'name' => trim((string) $data['name']),
                'subtitle' => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'cover_url' => $data['cover_url'] ?? null,
                'gallery_images' => array_values($data['gallery_images'] ?? []),
                'lock_version' => $locked->lock_version + 1,
            ]);
            $this->syncSkus($locked, $data['skus'], $campusId);
            if (($data['status'] ?? null) === StoreProduct::STATUS_ON_SALE) {
                $this->transition($locked, 'publish', null, $user);
            } elseif (($data['status'] ?? null) === StoreProduct::STATUS_OFF_SHELF) {
                $this->transition($locked, 'off_shelf', null, $user);
            }

            return $locked->fresh(['store', 'category', 'skus.inventories']);
        });
    }

    public function transition(StoreProduct $product, string $action, ?string $reason, ?User $user = null, ?int $adminId = null): StoreProduct
    {
        return DB::transaction(function () use ($product, $action, $reason, $user, $adminId): StoreProduct {
            $locked = StoreProduct::query()->lockForUpdate()->findOrFail($product->id);
            $from = $locked->status;
            $to = match ($action) {
                'publish' => StoreProduct::STATUS_ON_SALE,
                'off_shelf' => StoreProduct::STATUS_OFF_SHELF,
                default => throw ValidationException::withMessages(['action' => ['不支持的商品状态操作。']]),
            };
            if ($to === StoreProduct::STATUS_ON_SALE) {
                if (! $locked->store?->isActive()) {
                    throw ValidationException::withMessages(['store' => ['门店未正常营业，不能上架商品。']]);
                }
                if ($locked->admin_off_shelf_reason !== null && $adminId === null) {
                    throw ValidationException::withMessages(['status' => ['该商品已被平台下架，请联系平台运营处理。']]);
                }
                $activeSkus = $locked->skus()->where('status', 'active')->count();
                if ($activeSkus === 0) {
                    throw ValidationException::withMessages(['skus' => ['至少需要一个上架中的规格才能上架。']]);
                }
            }
            if ($from === $to && $action !== 'off_shelf') {
                return $locked;
            }
            $locked->update([
                'status' => $to,
                'published_at' => $to === StoreProduct::STATUS_ON_SALE ? ($locked->published_at ?? now()) : $locked->published_at,
                'off_shelf_at' => $to === StoreProduct::STATUS_OFF_SHELF ? now() : null,
                'admin_off_shelf_reason' => $adminId !== null && $to === StoreProduct::STATUS_OFF_SHELF ? $reason : $locked->admin_off_shelf_reason,
                'lock_version' => $locked->lock_version + 1,
            ]);
            StoreProductStatusChange::query()->create([
                'store_product_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'action' => $action,
                'reason' => $reason,
                'actor_user_id' => $user?->id,
                'actor_admin_id' => $adminId,
            ]);

            return $locked->fresh(['store', 'category', 'skus.inventories']);
        });
    }

    public function clearAdminBlock(StoreProduct $product, int $adminId): StoreProduct
    {
        return DB::transaction(function () use ($product, $adminId): StoreProduct {
            $locked = StoreProduct::query()->lockForUpdate()->findOrFail($product->id);
            if ($locked->admin_off_shelf_reason === null) {
                return $locked;
            }
            StoreProductStatusChange::query()->create([
                'store_product_id' => $locked->id,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'action' => 'clear_block',
                'reason' => '平台解除商品下架限制。',
                'actor_admin_id' => $adminId,
            ]);
            $locked->update(['admin_off_shelf_reason' => null, 'lock_version' => $locked->lock_version + 1]);

            return $locked->fresh(['store', 'category', 'skus.inventories']);
        });
    }

    /** @param array<int, array<string, mixed>> $skus */
    private function syncSkus(StoreProduct $product, array $skus, ?int $campusId): void
    {
        if ($skus === []) {
            throw ValidationException::withMessages(['skus' => ['至少需要填写一个商品规格。']]);
        }
        $existing = $product->skus()->get()->keyBy('public_id');
        $kept = [];
        foreach (array_values($skus) as $index => $input) {
            $id = (string) ($input['id'] ?? '');
            $sku = $id !== '' ? $existing->get($id) : null;
            if ($id !== '' && $sku === null) {
                throw ValidationException::withMessages(['skus' => ['包含不属于当前商品的规格。']]);
            }
            $attributes = [
                'name' => trim((string) $input['name']),
                'specs' => (array) ($input['specs'] ?? []),
                'image_url' => $input['image_url'] ?? null,
                'fulfillment_type' => (string) ($input['fulfillment_type'] ?? 'logistics'),
                'weight_grams' => isset($input['weight_grams']) ? (int) $input['weight_grams'] : null,
                'cash_amount' => (int) $input['cash_amount'],
                'limit_per_order' => isset($input['limit_per_order']) ? (int) $input['limit_per_order'] : null,
                'status' => (string) ($input['status'] ?? 'active'),
                'sort_order' => $index,
            ];
            if ($sku === null) {
                $sku = $product->skus()->create([...$attributes, 'sku_code' => $this->skuCode($product)]);
            } else {
                $sku->update([...$attributes, 'lock_version' => $sku->lock_version + 1]);
            }
            $kept[] = $sku->id;
            // 库存按校区独立：商品保存只设置当前校区的库存，其他校区在库存页单独补货
            $inventory = StoreInventory::query()->firstOrCreate(['store_product_sku_id' => $sku->id, 'campus_id' => $campusId]);
            if (array_key_exists('stock_quantity', $input) && $input['stock_quantity'] !== null) {
                $this->setOnHand($inventory, (int) $input['stock_quantity'], '商品保存时设置库存。', 'store-product-save:'.$sku->public_id.':'.$campusId.':'.$sku->lock_version);
            }
        }
        $removable = $product->skus()->whereNotIn('id', $kept)->get();
        foreach ($removable as $sku) {
            $reserved = (int) StoreInventory::query()->where('store_product_sku_id', $sku->id)->sum('reserved_quantity');
            if ((int) $reserved > 0) {
                throw ValidationException::withMessages(['skus' => ['存在已预占库存的规格，不能删除。']]);
            }
            $sku->update(['status' => 'inactive']);
        }
    }

    private function setOnHand(StoreInventory $inventory, int $quantity, string $reason, string $key): void
    {
        if ($quantity < 0) {
            throw ValidationException::withMessages(['stock_quantity' => ['库存不能为负数。']]);
        }
        $locked = StoreInventory::query()->lockForUpdate()->findOrFail($inventory->id);
        if ($quantity < $locked->reserved_quantity) {
            throw ValidationException::withMessages(['stock_quantity' => ['库存不能低于已预占数量。']]);
        }
        $before = (int) $locked->on_hand_quantity;
        if ($before === $quantity) {
            return;
        }
        $locked->update(['on_hand_quantity' => $quantity, 'version' => $locked->version + 1]);
        StoreInventoryTransaction::query()->create([
            'store_inventory_id' => $locked->id,
            'transaction_type' => 'manual_set',
            'on_hand_change' => $quantity - $before,
            'reserved_change' => 0,
            'on_hand_before' => $before,
            'on_hand_after' => $quantity,
            'reserved_before' => $locked->reserved_quantity,
            'reserved_after' => $locked->reserved_quantity,
            'business_type' => 'store_product',
            'business_id' => $reason,
            'reason' => $reason,
            'idempotency_key_hash' => hash('sha256', $key),
        ]);
    }

    private function validateDefinition(array $data): void
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw ValidationException::withMessages(['name' => ['请填写商品名称。']]);
        }
        if (mb_strlen((string) $data['name']) > 160) {
            throw ValidationException::withMessages(['name' => ['商品名称不能超过 160 个字。']]);
        }
        if (mb_strlen((string) ($data['description'] ?? '')) > 20000) {
            throw ValidationException::withMessages(['description' => ['商品详情过长。']]);
        }
        if (count($data['gallery_images'] ?? []) > 9) {
            throw ValidationException::withMessages(['gallery_images' => ['商品图最多 9 张。']]);
        }
        $skus = array_values($data['skus'] ?? []);
        if (count($skus) > 30) {
            throw ValidationException::withMessages(['skus' => ['单个商品最多 30 个规格。']]);
        }
        foreach ($skus as $index => $sku) {
            $position = $index + 1;
            if (trim((string) ($sku['name'] ?? '')) === '') {
                throw ValidationException::withMessages(['skus' => ["第 {$position} 个规格缺少名称。"]]);
            }
            $amount = (int) ($sku['cash_amount'] ?? 0);
            if ($amount < 1 || $amount > 10000000) {
                throw ValidationException::withMessages(['skus' => ["第 {$position} 个规格价格需在 0.01 至 100000 元之间。"]]);
            }
            if (! in_array((string) ($sku['fulfillment_type'] ?? 'logistics'), ['logistics', 'no_logistics'], true)) {
                throw ValidationException::withMessages(['skus' => ["第 {$position} 个规格的交付方式无效。"]]);
            }
            if (isset($sku['stock_quantity']) && ((int) $sku['stock_quantity'] < 0 || (int) $sku['stock_quantity'] > 1000000)) {
                throw ValidationException::withMessages(['skus' => ["第 {$position} 个规格库存超出范围。"]]);
            }
        }
    }

    private function assertStoreActive(Store $store): void
    {
        if (! $store->isActive()) {
            throw ValidationException::withMessages(['store' => ['门店未正常营业，无法管理商品。']]);
        }
    }

    private function categoryId(Store $store, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        $id = StoreCategory::query()->where('store_id', $store->id)->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['category_id' => ['商品分类不属于当前门店。']]);
        }

        return (int) $id;
    }

    private function skuCode(StoreProduct $product): string
    {
        return 'SKU'.$product->id.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }
}
