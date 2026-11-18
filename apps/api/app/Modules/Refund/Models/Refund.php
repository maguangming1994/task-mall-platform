<?php

namespace App\Modules\Refund\Models;

use App\Models\User;
use App\Modules\Order\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Refund extends Model
{
    protected $fillable = ['public_id', 'refund_no', 'order_id', 'user_id', 'service_type', 'reason_code', 'reason_name_snapshot', 'description', 'status', 'refund_cash_amount', 'refund_wallet_amount', 'refund_external_amount', 'refund_points', 'coupon_returned', 'idempotency_key_hash', 'request_fingerprint_hash', 'reviewed_by_admin_id', 'review_note', 'responsibility_party', 'return_shipping_payer', 'return_method', 'reason_rule_snapshot', 'evidence_file_ids', 'user_action_deadline_at', 'original_shipping_refunded', 'completed_at'];

    protected $hidden = ['idempotency_key_hash', 'request_fingerprint_hash'];

    protected static function booted(): void
    {
        static::creating(fn (Refund $m) => $m->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['refund_cash_amount' => 'integer', 'refund_wallet_amount' => 'integer', 'refund_external_amount' => 'integer', 'refund_points' => 'integer', 'coupon_returned' => 'boolean', 'reason_rule_snapshot' => 'array', 'evidence_file_ids' => 'array', 'user_action_deadline_at' => 'datetime', 'original_shipping_refunded' => 'boolean', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function returnShipment(): HasOne
    {
        return $this->hasOne(ReturnShipment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
