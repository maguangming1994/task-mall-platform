<?php

namespace App\Modules\Order\Models;

use App\Models\User;
use App\Modules\Fulfillment\Models\Fulfillment;
use App\Modules\Refund\Models\Exchange;
use App\Modules\Refund\Models\Refund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = ['public_id', 'order_no', 'order_batch_id', 'user_id', 'fulfillment_type', 'status', 'cash_payment_method', 'address_snapshot', 'user_coupon_id', 'coupon_snapshot', 'shipping_rule_snapshot', 'items_cash_amount', 'items_points', 'cash_discount_amount', 'points_discount', 'shipping_amount', 'payable_cash_amount', 'payable_points', 'paid_wallet_amount', 'paid_external_amount', 'paid_points', 'refunded_wallet_amount', 'refunded_external_amount', 'refunded_points', 'expires_at', 'paid_at', 'fulfill_by', 'shipped_at', 'auto_complete_at', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (Order $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['address_snapshot' => 'array', 'coupon_snapshot' => 'array', 'shipping_rule_snapshot' => 'array', 'items_cash_amount' => 'integer', 'items_points' => 'integer', 'cash_discount_amount' => 'integer', 'points_discount' => 'integer', 'shipping_amount' => 'integer', 'payable_cash_amount' => 'integer', 'payable_points' => 'integer', 'paid_wallet_amount' => 'integer', 'paid_external_amount' => 'integer', 'paid_points' => 'integer', 'refunded_wallet_amount' => 'integer', 'refunded_external_amount' => 'integer', 'refunded_points' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'fulfill_by' => 'datetime', 'shipped_at' => 'datetime', 'auto_complete_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OrderBatch::class, 'order_batch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function exchanges(): HasMany
    {
        return $this->hasMany(Exchange::class);
    }
}
