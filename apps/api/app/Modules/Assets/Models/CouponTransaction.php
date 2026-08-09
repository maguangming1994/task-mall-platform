<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CouponTransaction extends Model
{
    protected $fillable = ['public_id', 'user_id', 'user_coupon_id', 'transaction_type', 'status_before', 'status_after', 'business_type', 'business_id', 'event_key_hash', 'admin_user_id', 'reason'];

    protected static function booted(): void
    {
        static::creating(fn (CouponTransaction $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Coupon transactions are append only.'));
        static::deleting(fn () => throw new \LogicException('Coupon transactions are append only.'));
    }
}
