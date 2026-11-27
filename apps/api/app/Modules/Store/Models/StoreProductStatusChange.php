<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProductStatusChange extends Model
{
    protected $fillable = ['store_product_id', 'from_status', 'to_status', 'action', 'reason', 'actor_user_id', 'actor_admin_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'store_product_id');
    }
}
