<?php

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CheckoutPreview extends Model
{
    protected $fillable = ['public_id', 'user_id', 'token_hash', 'request_hash', 'request_snapshot', 'pricing_snapshot', 'expires_at', 'consumed_at'];

    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::creating(fn (CheckoutPreview $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['request_snapshot' => 'array', 'pricing_snapshot' => 'array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
