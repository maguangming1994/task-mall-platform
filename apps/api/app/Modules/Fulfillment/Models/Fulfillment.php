<?php

namespace App\Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Fulfillment extends Model
{
    protected $fillable = ['public_id', 'fulfillment_no', 'order_id', 'method', 'status', 'carrier_code', 'carrier_name', 'tracking_no', 'no_logistics_reason', 'virtual_delivery_snapshot', 'idempotency_key_hash', 'created_by_admin_id', 'fulfilled_at'];

    protected static function booted(): void
    {
        static::creating(fn (Fulfillment $m) => $m->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['virtual_delivery_snapshot' => 'array', 'fulfilled_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
