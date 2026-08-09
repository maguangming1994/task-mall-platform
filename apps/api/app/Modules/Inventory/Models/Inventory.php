<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Product\Models\ProductSku;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $fillable = ['product_sku_id', 'on_hand_quantity', 'reserved_quantity', 'version'];

    protected $attributes = ['on_hand_quantity' => 0, 'reserved_quantity' => 0, 'version' => 1];

    protected function casts(): array
    {
        return ['on_hand_quantity' => 'integer', 'reserved_quantity' => 'integer', 'version' => 'integer'];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'product_sku_id');
    }
}
