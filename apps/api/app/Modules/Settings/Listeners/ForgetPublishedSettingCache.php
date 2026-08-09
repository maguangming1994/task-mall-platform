<?php

namespace App\Modules\Settings\Listeners;

use App\Modules\Settings\Events\SystemSettingPublished;
use Illuminate\Support\Facades\Cache;

class ForgetPublishedSettingCache
{
    public function handle(SystemSettingPublished $event): void
    {
        Cache::forget('system-setting:active:'.$event->settingKey);
    }
}
