<?php

namespace App\Modules\ExpressPickup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpressPickupOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'event', 'actor_type', 'actor_id', 'reason',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExpressPickupOrder::class, 'order_id');
    }
}
