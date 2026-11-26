<?php

namespace App\Modules\ExpressPickup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressPickupSettlementItem extends Model
{
    protected $fillable = [
        'settlement_id', 'runner_user_id', 'order_count', 'amount_cents', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'order_count' => 'integer',
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'runner_user_id');
    }
}
