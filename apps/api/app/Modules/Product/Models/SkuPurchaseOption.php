<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SkuPurchaseOption extends Model
{
    protected $fillable = ['public_id', 'product_sku_id', 'purchase_mode', 'cash_amount', 'points', 'enabled', 'channels', 'starts_at', 'ends_at', 'lock_version'];

    protected $attributes = ['enabled' => true, 'lock_version' => 1];

    protected static function booted(): void
    {
        static::creating(fn (SkuPurchaseOption $option) => $option->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['cash_amount' => 'integer', 'points' => 'integer', 'enabled' => 'boolean', 'channels' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'lock_version' => 'integer'];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'product_sku_id');
    }
}
