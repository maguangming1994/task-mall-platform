<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserCoupon extends Model
{
    protected $fillable = ['public_id', 'user_id', 'coupon_template_id', 'status', 'template_snapshot', 'issue_event_key_hash', 'valid_from', 'valid_to', 'used_at'];

    protected $hidden = ['issue_event_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (UserCoupon $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['template_snapshot' => 'array', 'valid_from' => 'datetime', 'valid_to' => 'datetime', 'used_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
