<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    protected $fillable = ['public_id', 'user_id', 'category', 'scene_code', 'title', 'body', 'data', 'essential', 'event_key_hash', 'read_at'];

    protected static function booted(): void
    {
        static::creating(fn (Notification $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'essential' => 'boolean', 'read_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
