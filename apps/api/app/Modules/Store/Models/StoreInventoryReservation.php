<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreInventoryReservation extends Model
{
    protected $fillable = ['public_id', 'order_item_id', 'store_inventory_id', 'quantity', 'status', 'expires_at', 'consumed_at', 'released_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(StoreInventory::class, 'store_inventory_id');
    }
}
