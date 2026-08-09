<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RewardBudgetPool extends Model
{
    protected $fillable = ['public_id', 'name', 'asset_type', 'coupon_template_public_id', 'total_amount', 'locked_amount', 'issued_amount', 'status', 'version', 'created_by_admin_id', 'idempotency_key_hash'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (RewardBudgetPool $pool) => $pool->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'locked_amount' => 'integer', 'issued_amount' => 'integer', 'version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
