<?php

namespace App\Modules\Refund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ExchangeShipment extends Model
{
    protected $fillable = ['public_id', 'exchange_id', 'direction', 'method', 'carrier_code', 'tracking_no', 'no_logistics_note', 'payment_proof_file_id', 'paid_shipping_amount', 'compensation_amount', 'status', 'idempotency_key_hash', 'shipped_at', 'received_at'];

    protected static function booted(): void
    {
        static::creating(fn (ExchangeShipment $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['paid_shipping_amount' => 'integer', 'compensation_amount' => 'integer', 'shipped_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
