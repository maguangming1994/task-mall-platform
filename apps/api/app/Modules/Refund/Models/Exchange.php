<?php

namespace App\Modules\Refund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Exchange extends Model
{
    protected $fillable = ['public_id', 'exchange_no', 'user_id', 'order_id', 'order_item_id', 'quantity', 'reason_code', 'reason_name_snapshot', 'reason_rule_snapshot', 'description', 'evidence_file_ids', 'return_method', 'status', 'responsibility_party', 'return_shipping_payer', 'replacement_shipping_payer', 'return_deadline_at', 'replacement_auto_complete_at', 'idempotency_key_hash', 'request_fingerprint_hash', 'reviewed_by_admin_id', 'review_note', 'completed_at'];

    protected $hidden = ['idempotency_key_hash', 'request_fingerprint_hash'];

    protected static function booted(): void
    {
        static::creating(fn (Exchange $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reason_rule_snapshot' => 'array', 'evidence_file_ids' => 'array', 'return_deadline_at' => 'datetime', 'replacement_auto_complete_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(ExchangeShipment::class);
    }
}
