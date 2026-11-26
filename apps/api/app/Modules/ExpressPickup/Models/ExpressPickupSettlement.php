<?php

namespace App\Modules\ExpressPickup\Models;

use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExpressPickupSettlement extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'batch_no', 'campus_id', 'period_start', 'period_end', 'status',
        'total_cents', 'runner_count', 'order_count', 'created_by_admin_id',
        'confirmed_at', 'paid_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $settlement) => $settlement->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_cents' => 'integer',
            'runner_count' => 'integer',
            'order_count' => 'integer',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpressPickupSettlementItem::class, 'settlement_id');
    }
}
