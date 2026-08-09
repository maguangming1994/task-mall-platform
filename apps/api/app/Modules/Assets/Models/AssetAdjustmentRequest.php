<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AssetAdjustmentRequest extends Model
{
    protected $fillable = ['public_id', 'user_id', 'asset_type', 'action', 'amount', 'coupon_template_id', 'status', 'requires_review', 'reason', 'requested_by_admin_id', 'reviewed_by_admin_id', 'review_note', 'idempotency_key_hash', 'review_idempotency_key_hash', 'reviewed_at', 'applied_at'];

    protected $hidden = ['idempotency_key_hash', 'review_idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (AssetAdjustmentRequest $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'requires_review' => 'boolean', 'reviewed_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
