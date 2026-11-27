<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StoreInventoryTransaction extends Model
{
    protected $fillable = [
        'public_id', 'store_inventory_id', 'transaction_type', 'on_hand_change',
        'reserved_change', 'on_hand_before', 'on_hand_after', 'reserved_before',
        'reserved_after', 'business_type', 'business_id', 'reason', 'idempotency_key_hash',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $transaction) => $transaction->public_id ??= (string) Str::ulid());
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(StoreInventory::class, 'store_inventory_id');
    }
}
