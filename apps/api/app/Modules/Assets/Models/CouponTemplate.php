<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CouponTemplate extends Model
{
    protected $fillable = ['public_id', 'name', 'cash_discount_cents', 'points_discount', 'minimum_cash_cents', 'minimum_points', 'valid_days', 'total_quantity', 'issued_quantity', 'status', 'version', 'created_by_admin_id', 'idempotency_key_hash'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (CouponTemplate $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['cash_discount_cents' => 'integer', 'points_discount' => 'integer', 'minimum_cash_cents' => 'integer', 'minimum_points' => 'integer', 'valid_days' => 'integer', 'total_quantity' => 'integer', 'issued_quantity' => 'integer', 'version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
