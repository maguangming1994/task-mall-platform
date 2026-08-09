<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FeatureFlagVersion extends Model
{
    protected $fillable = ['feature_flag_id', 'version', 'mode', 'rollout_percentage', 'audience', 'reason', 'created_by_admin_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Feature flag versions are append-only.'));
        static::deleting(fn () => throw new LogicException('Feature flag versions are append-only.'));
    }

    protected function casts(): array
    {
        return ['rollout_percentage' => 'integer', 'audience' => 'array', 'version' => 'integer'];
    }
}
