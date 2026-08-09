<?php

namespace App\Modules\Cart\Services;

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Product\Models\ProductSku;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function add(User $user, ProductSku $sku, string $mode, int $quantity): CartItem
    {
        return DB::transaction(function () use ($user, $sku, $mode, $quantity) {
            $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
            $this->assertSelectable($sku, $mode, $quantity);
            $item = CartItem::query()->where('cart_id', $cart->id)->where('product_sku_id', $sku->id)->where('purchase_mode', $mode)->lockForUpdate()->first();
            $newQuantity = ($item?->quantity ?? 0) + $quantity;
            $this->assertQuantity($sku, $newQuantity);
            if (! $item && $cart->items()->count() >= 100) {
                throw ValidationException::withMessages(['cart' => '购物车最多保存 100 个不同商品方案。']);
            }
            if ($item) {
                $item->update(['quantity' => $newQuantity, 'selected' => true]);

                return $item->fresh();
            }

            return $cart->items()->create(['product_sku_id' => $sku->id, 'purchase_mode' => $mode, 'quantity' => $quantity, 'selected' => true]);
        });
    }

    public function update(User $user, CartItem $item, array $data): CartItem
    {
        $this->authorize($user, $item);
        $quantity = (int) ($data['quantity'] ?? $item->quantity);
        $this->assertQuantity($item->sku, $quantity);
        $item->update(['quantity' => $quantity, 'selected' => $data['selected'] ?? $item->selected]);

        return $item->fresh();
    }

    public function delete(User $user, CartItem $item): void
    {
        $this->authorize($user, $item);
        $item->delete();
    }

    public function present(User $user): array
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        $cart->load(['items.sku.product', 'items.sku.purchaseOptions', 'items.sku.inventory']);
        $items = $cart->items->map(function (CartItem $item) {
            $sku = $item->sku;
            $option = $sku->purchaseOptions->firstWhere('purchase_mode', $item->purchase_mode);
            $available = $sku->inventory?->on_hand_quantity - $sku->inventory?->reserved_quantity;
            $reason = $sku->product->status !== 'on_sale' ? 'product_off_shelf'
                : ($sku->status !== 'active' ? 'sku_inactive'
                    : (! $option || ! $option->enabled ? 'purchase_mode_unavailable'
                        : ($available < $item->quantity ? 'insufficient_stock' : null)));

            return [
                'id' => $item->public_id, 'product_id' => $sku->product->public_id, 'product_name' => $sku->product->name,
                'sku_id' => $sku->public_id, 'sku_name' => $sku->name, 'specs' => $sku->specs,
                'fulfillment_type' => $sku->fulfillment_type, 'purchase_mode' => $item->purchase_mode,
                'quantity' => $item->quantity, 'selected' => $item->selected,
                'unit_cash_amount' => $option?->cash_amount, 'unit_points' => $option?->points,
                'available_quantity' => max(0, (int) $available), 'valid' => $reason === null, 'invalid_reason' => $reason,
            ];
        });

        return ['items' => $items, 'summary' => ['item_count' => $items->count(), 'selected_quantity' => $items->where('selected', true)->sum('quantity'), 'all_valid' => $items->where('selected', true)->every('valid', true)]];
    }

    private function assertSelectable(ProductSku $sku, string $mode, int $quantity): void
    {
        $sku->loadMissing(['product', 'purchaseOptions', 'inventory']);
        if ($sku->product->status !== 'on_sale' || $sku->status !== 'active') {
            throw ValidationException::withMessages(['sku_id' => '商品或 SKU 已下架。']);
        }
        $option = $sku->purchaseOptions->firstWhere('purchase_mode', $mode);
        if (! $option || ! $option->enabled) {
            throw ValidationException::withMessages(['purchase_mode' => '所选购买方式不可用。']);
        }
        $this->assertQuantity($sku, $quantity);
    }

    private function assertQuantity(ProductSku $sku, int $quantity): void
    {
        if ($quantity < 1 || ($sku->limit_per_order && $quantity > $sku->limit_per_order)) {
            throw ValidationException::withMessages(['quantity' => '数量超过单笔限购。']);
        }
        $inventory = $sku->inventory()->first();
        if (! $inventory || $inventory->on_hand_quantity - $inventory->reserved_quantity < $quantity) {
            throw ValidationException::withMessages(['quantity' => '当前可售库存不足。']);
        }
    }

    private function authorize(User $user, CartItem $item): void
    {
        abort_unless($item->cart()->where('user_id', $user->id)->exists(), 404);
    }
}
