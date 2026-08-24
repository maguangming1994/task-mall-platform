<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationBusinessEvent extends Model
{
    protected $fillable = ['public_id', 'user_id', 'scene_code', 'event_key_hash', 'variables', 'data', 'status', 'attempt_count', 'next_retry_at', 'last_error_code', 'last_error_message', 'processed_at'];

    protected static function booted(): void
    {
        static::creating(fn (NotificationBusinessEvent $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['variables' => 'array', 'data' => 'array', 'attempt_count' => 'integer', 'next_retry_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
