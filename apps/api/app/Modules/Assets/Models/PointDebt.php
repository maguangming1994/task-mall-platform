<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PointDebt extends Model
{
    protected $fillable = ['public_id', 'user_id', 'source_code', 'source_business_id', 'original_debt_points', 'remaining_debt_points', 'status', 'reason', 'event_key_hash', 'settled_at'];

    protected static function booted(): void
    {
        static::creating(fn (PointDebt $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['original_debt_points' => 'integer', 'remaining_debt_points' => 'integer', 'settled_at' => 'datetime'];
    }
}
