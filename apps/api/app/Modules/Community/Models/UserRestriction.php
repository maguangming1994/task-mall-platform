<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class UserRestriction extends Model
{
    protected $fillable = ['public_id', 'user_id', 'moderation_action_id', 'restriction_type', 'reason_code', 'status', 'starts_at', 'ends_at', 'revoked_by_admin_id', 'revoked_at'];

    protected static function booted(): void
    {
        static::creating(fn (UserRestriction $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
