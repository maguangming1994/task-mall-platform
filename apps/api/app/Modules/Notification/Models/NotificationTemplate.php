<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationTemplate extends Model
{
    protected $fillable = ['public_id', 'scene_code', 'channel', 'locale', 'status', 'current_version_no', 'created_by_admin_id'];

    protected static function booted(): void
    {
        static::creating(fn (NotificationTemplate $model) => $model->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
