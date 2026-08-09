<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InventoryTransaction extends Model
{
    protected $fillable = ['public_id', 'inventory_id', 'transaction_type', 'on_hand_change', 'reserved_change', 'on_hand_before', 'on_hand_after', 'reserved_before', 'reserved_after', 'business_type', 'business_id', 'admin_user_id', 'reason', 'idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (InventoryTransaction $item) => $item->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Inventory transactions are append-only.'));
        static::deleting(fn () => throw new \LogicException('Inventory transactions are append-only.'));
    }
}
