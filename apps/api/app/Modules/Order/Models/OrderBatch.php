<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrderBatch extends Model
{
    protected $fillable = ['public_id', 'batch_no', 'user_id', 'client_platform', 'capability_version', 'status', 'idempotency_key_hash', 'checkout_preview_id', 'items_cash_amount', 'items_points', 'cash_discount_amount', 'points_discount', 'shipping_amount', 'payable_cash_amount', 'payable_points'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (OrderBatch $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['items_cash_amount' => 'integer', 'items_points' => 'integer', 'cash_discount_amount' => 'integer', 'points_discount' => 'integer', 'shipping_amount' => 'integer', 'payable_cash_amount' => 'integer', 'payable_points' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
