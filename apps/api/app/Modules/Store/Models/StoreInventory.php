<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreInventory extends Model
{
    protected $fillable = ['store_product_sku_id', 'campus_id', 'on_hand_quantity', 'reserved_quantity', 'version'];

    protected $attributes = ['on_hand_quantity' => 0, 'reserved_quantity' => 0, 'version' => 1];

    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'on_hand_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'version' => 'integer',
        ];
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(StoreProductSku::class, 'store_product_sku_id');
    }

    public function available(): int
    {
        return max(0, $this->on_hand_quantity - $this->reserved_quantity);
    }
}
