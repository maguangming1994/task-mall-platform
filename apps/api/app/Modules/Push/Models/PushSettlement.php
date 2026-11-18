<?php

namespace App\Modules\Push\Models;

use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PushSettlement extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'batch_no', 'period_start', 'period_end', 'status',
        'submission_count', 'total_commission_cents', 'total_team_bonus_cents',
        'created_by_admin_id', 'confirmed_at', 'paid_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PushSettlement $settlement) => $settlement->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'submission_count' => 'integer',
            'total_commission_cents' => 'integer',
            'total_team_bonus_cents' => 'integer',
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
        return $this->hasMany(PushSettlementItem::class, 'settlement_id');
    }
}
