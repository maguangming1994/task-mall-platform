<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSettlementItem extends Model
{
    protected $fillable = ['store_settlement_id', 'order_item_id', 'order_id', 'gross_amount', 'refunded_amount', 'commission_amount', 'net_amount'];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'refunded_amount' => 'integer',
            'commission_amount' => 'integer',
            'net_amount' => 'integer',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(StoreSettlement::class, 'store_settlement_id');
    }
}
