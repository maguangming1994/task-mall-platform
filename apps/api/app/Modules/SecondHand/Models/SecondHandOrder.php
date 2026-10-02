<?php

namespace App\Modules\SecondHand\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SecondHandOrder extends Model
{
    protected $fillable = [
        'public_id', 'order_no', 'buyer_user_id', 'seller_user_id', 'item_id',
        'title_snapshot', 'cover_snapshot', 'price', 'trade_type',
        'address_snapshot', 'status', 'cash_payment_method', 'paid_amount',
        'idempotency_key_hash', 'paid_at', 'shipped_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'address_snapshot' => 'array',
        'price' => 'integer',
        'paid_amount' => 'integer',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m): void {
            $m->public_id ??= (string) Str::ulid();
            $m->order_no ??= 'SH'.date('YmdHis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SecondHandItem::class, 'item_id');
    }
}
