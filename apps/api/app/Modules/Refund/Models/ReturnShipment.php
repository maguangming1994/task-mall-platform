<?php

namespace App\Modules\Refund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReturnShipment extends Model
{
    protected $fillable = ['public_id', 'refund_id', 'method', 'carrier_code', 'tracking_no', 'payment_proof_file_id', 'paid_shipping_amount', 'compensation_amount', 'responsibility_party', 'status', 'idempotency_key_hash', 'submitted_at', 'received_at'];

    protected static function booted(): void
    {
        static::creating(fn (ReturnShipment $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['paid_shipping_amount' => 'integer', 'compensation_amount' => 'integer', 'submitted_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
