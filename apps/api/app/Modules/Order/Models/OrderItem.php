<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    protected $fillable = ['public_id', 'order_id', 'product_sku_id', 'product_public_id', 'sku_public_id', 'product_name', 'sku_name', 'specs_snapshot', 'image_file_public_id', 'purchase_mode', 'fulfillment_type', 'quantity', 'unit_cash_amount', 'unit_points', 'cash_subtotal', 'points_subtotal', 'cash_discount_allocated', 'points_discount_allocated', 'shipping_allocated', 'paid_cash_amount', 'paid_points', 'refunded_quantity', 'refunded_cash_amount', 'refunded_points', 'purchase_limit_snapshot'];

    protected static function booted(): void
    {
        static::creating(fn (OrderItem $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['specs_snapshot' => 'array', 'purchase_limit_snapshot' => 'array', 'quantity' => 'integer', 'unit_cash_amount' => 'integer', 'unit_points' => 'integer', 'cash_subtotal' => 'integer', 'points_subtotal' => 'integer', 'cash_discount_allocated' => 'integer', 'points_discount_allocated' => 'integer', 'shipping_allocated' => 'integer', 'paid_cash_amount' => 'integer', 'paid_points' => 'integer', 'refunded_quantity' => 'integer', 'refunded_cash_amount' => 'integer', 'refunded_points' => 'integer'];
    }
}
