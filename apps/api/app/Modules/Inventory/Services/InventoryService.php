<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryTransaction;
use App\Modules\Product\Models\ProductSku;
use App\Modules\Settings\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function ensure(ProductSku $sku): Inventory
    {
        return Inventory::query()->firstOrCreate(['product_sku_id' => $sku->id]);
    }

    public function adjust(AdminUser $admin, ProductSku $sku, string $action, int $quantity, int $expectedVersion, string $reason, string $idempotencyKey): Inventory
    {
        $hash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($admin, $sku, $action, $quantity, $expectedVersion, $reason, $hash) {
            $existing = InventoryTransaction::query()->where('idempotency_key_hash', $hash)->first();
            if ($existing) {
                return Inventory::query()->findOrFail($existing->inventory_id);
            }
            $inventory = Inventory::query()->where('product_sku_id', $sku->id)->lockForUpdate()->firstOrCreate(['product_sku_id' => $sku->id]);
            if ($inventory->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => '库存已变化，请刷新后重试。']);
            }
            $before = $inventory->on_hand_quantity;
            $after = match ($action) {
                'increase' => $before + $quantity,
                'decrease' => $before - $quantity,
                'set' => $quantity,
                default => throw ValidationException::withMessages(['action' => '库存调整方式无效。']),
            };
            if ($after < $inventory->reserved_quantity) {
                throw ValidationException::withMessages(['quantity' => '库存调整不能侵占已预占数量。']);
            }
            $inventory->update(['on_hand_quantity' => $after, 'version' => $inventory->version + 1]);
            InventoryTransaction::query()->create([
                'inventory_id' => $inventory->id, 'transaction_type' => 'manual_'.$action,
                'on_hand_change' => $after - $before, 'reserved_change' => 0,
                'on_hand_before' => $before, 'on_hand_after' => $after,
                'reserved_before' => $inventory->reserved_quantity, 'reserved_after' => $inventory->reserved_quantity,
                'admin_user_id' => $admin->id, 'reason' => $reason, 'idempotency_key_hash' => $hash,
            ]);

            return $inventory->fresh();
        });
    }

    public function present(Inventory $inventory): array
    {
        $inventory->loadMissing('sku.product');
        $threshold = $inventory->sku->low_stock_threshold ?? (int) $this->settings->read('orders.low_stock_threshold')['value'];
        $available = $inventory->on_hand_quantity - $inventory->reserved_quantity;

        return [
            'sku_id' => $inventory->sku->public_id, 'sku_code' => $inventory->sku->sku_code,
            'product_id' => $inventory->sku->product->public_id, 'product_name' => $inventory->sku->product->name,
            'on_hand_quantity' => $inventory->on_hand_quantity, 'reserved_quantity' => $inventory->reserved_quantity,
            'available_quantity' => $available, 'low_stock_threshold' => $threshold,
            'low_stock' => $available <= $threshold, 'version' => $inventory->version,
        ];
    }
}
