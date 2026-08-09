<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    protected $fillable = ['public_id', 'user_id', 'transaction_type', 'change_available_cents', 'change_frozen_cents', 'available_after_cents', 'frozen_after_cents', 'business_type', 'business_id', 'event_key_hash', 'admin_user_id', 'reason'];

    protected static function booted(): void
    {
        static::creating(fn (WalletTransaction $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Wallet transactions are append only.'));
        static::deleting(fn () => throw new \LogicException('Wallet transactions are append only.'));
    }

    protected function casts(): array
    {
        return ['change_available_cents' => 'integer', 'change_frozen_cents' => 'integer', 'available_after_cents' => 'integer', 'frozen_after_cents' => 'integer'];
    }
}
