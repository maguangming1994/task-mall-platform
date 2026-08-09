<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PointTransaction extends Model
{
    protected $fillable = ['public_id', 'user_id', 'point_batch_id', 'point_debt_id', 'transaction_type', 'change_available', 'change_frozen', 'change_debt', 'available_after', 'frozen_after', 'debt_after', 'source_code', 'source_business_id', 'event_key_hash', 'admin_user_id', 'reason'];

    protected static function booted(): void
    {
        static::creating(fn (PointTransaction $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Point transactions are append only.'));
        static::deleting(fn () => throw new \LogicException('Point transactions are append only.'));
    }

    protected function casts(): array
    {
        return ['change_available' => 'integer', 'change_frozen' => 'integer', 'change_debt' => 'integer', 'available_after' => 'integer', 'frozen_after' => 'integer', 'debt_after' => 'integer'];
    }
}
