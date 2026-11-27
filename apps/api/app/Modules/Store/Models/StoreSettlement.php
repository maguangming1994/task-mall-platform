<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StoreSettlement extends Model
{
    protected $fillable = [
        'public_id', 'store_id', 'campus_id', 'batch_no', 'period_start', 'period_end', 'gross_amount',
        'refunded_amount', 'commission_amount', 'net_amount', 'item_count', 'status',
        'remark', 'confirmed_at', 'paid_at', 'created_by_admin_id',
    ];

    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::creating(fn (self $settlement) => $settlement->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'integer',
            'refunded_amount' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
            'item_count' => 'integer',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Campus\Models\Campus::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StoreSettlementItem::class);
    }
}
