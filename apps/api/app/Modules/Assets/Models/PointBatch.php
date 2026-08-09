<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PointBatch extends Model
{
    protected $fillable = ['public_id', 'user_id', 'source_code', 'source_business_id', 'issued_points', 'remaining_points', 'frozen_points', 'rule_version', 'status', 'event_key_hash', 'issued_at', 'expires_at'];

    protected static function booted(): void
    {
        static::creating(fn (PointBatch $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['issued_points' => 'integer', 'remaining_points' => 'integer', 'frozen_points' => 'integer', 'rule_version' => 'integer', 'issued_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
