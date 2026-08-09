<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'in_app_enabled', 'wechat_enabled', 'sms_enabled', 'marketing_subscribed', 'quiet_hours_start', 'quiet_hours_end'];

    protected function casts(): array
    {
        return ['in_app_enabled' => 'boolean', 'wechat_enabled' => 'boolean', 'sms_enabled' => 'boolean', 'marketing_subscribed' => 'boolean'];
    }
}
