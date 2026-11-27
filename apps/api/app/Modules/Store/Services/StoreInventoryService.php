<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreInventory;
use App\Modules\Store\Models\StoreInventoryTransaction;
use App\Modules\Store\Models\StoreProductSku;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreInventoryService
{
    public function adjust(StoreProductSku $sku, int $campusId, string $action, int $quantity, int $expectedVersion, string $reason, string $idempotencyKey): StoreInventory
    {
        $hash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($sku, $campusId, $action, $quantity, $expectedVersion, $reason, $hash): StoreInventory {
            $existing = StoreInventoryTransaction::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing !== null) {
                return StoreInventory::query()->findOrFail($existing->store_inventory_id);
            }
            $inventory = StoreInventory::query()
                ->where('store_product_sku_id', $sku->id)
                ->where('campus_id', $campusId)
                ->lockForUpdate()
                ->firstOrCreate(['store_product_sku_id' => $sku->id, 'campus_id' => $campusId]);
            if ((int) $inventory->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => ['库存已变化，请刷新后重试。']]);
            }
            $before = (int) $inventory->on_hand_quantity;
            $after = match ($action) {
                'increase' => $before + $quantity,
                'decrease' => $before - $quantity,
                'set' => $quantity,
                default => throw ValidationException::withMessages(['action' => ['库存调整方式无效。']]),
            };
            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => ['库存不能为负数。']]);
            }
            if ($after < $inventory->reserved_quantity) {
                throw ValidationException::withMessages(['quantity' => ['库存调整不能侵占已预占数量。']]);
            }
            $inventory->update(['on_hand_quantity' => $after, 'version' => $inventory->version + 1]);
            StoreInventoryTransaction::query()->create([
                'store_inventory_id' => $inventory->id,
                'transaction_type' => 'manual_'.$action,
                'on_hand_change' => $after - $before,
                'reserved_change' => 0,
                'on_hand_before' => $before,
                'on_hand_after' => $after,
                'reserved_before' => $inventory->reserved_quantity,
                'reserved_after' => $inventory->reserved_quantity,
                'reason' => $reason,
                'idempotency_key_hash' => $hash,
            ]);

            return $inventory->fresh();
        });
    }

    /** 校区维度展示：无库存行时返回零库存结构，便于门店直接补货。 */
    public function presentForCampus(StoreProductSku $sku, ?StoreInventory $inventory): array
    {
        $sku->loadMissing('product');

        return [
            'sku_id' => $sku->public_id,
            'sku_name' => $sku->name,
            'specs' => $sku->specs ?? [],
            'product_id' => $sku->product->public_id,
            'product_name' => $sku->product->name,
            'on_hand_quantity' => $inventory === null ? 0 : (int) $inventory->on_hand_quantity,
            'reserved_quantity' => $inventory === null ? 0 : (int) $inventory->reserved_quantity,
            'available_quantity' => $inventory === null ? 0 : $inventory->available(),
            'version' => $inventory === null ? 1 : (int) $inventory->version,
            'low_stock' => $inventory === null || $inventory->available() <= 5,
        ];
    }

    public function assertOwner(Store $store, StoreProductSku $sku): void
    {
        $belongs = StoreProductSku::query()
            ->whereKey($sku->id)
            ->whereHas('product', fn ($query) => $query->where('store_id', $store->id))
            ->exists();
        abort_unless($belongs, 404);
    }
}
