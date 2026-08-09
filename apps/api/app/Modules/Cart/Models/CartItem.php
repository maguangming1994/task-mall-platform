<?php

namespace App\Modules\Cart\Models;

use App\Modules\Product\Models\ProductSku;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CartItem extends Model
{
    protected $fillable = ['public_id', 'cart_id', 'product_sku_id', 'purchase_mode', 'quantity', 'selected'];

    protected $attributes = ['selected' => true];

    protected static function booted(): void
    {
        static::creating(fn (CartItem $item) => $item->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'selected' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'product_sku_id');
    }
}
