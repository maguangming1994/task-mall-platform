<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSceneConfig extends Model
{
    protected $fillable = ['scene_code', 'enabled', 'channels', 'lock_version', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'channels' => 'array', 'lock_version' => 'integer'];
    }
}
