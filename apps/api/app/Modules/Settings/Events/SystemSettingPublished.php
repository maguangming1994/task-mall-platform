<?php

namespace App\Modules\Settings\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemSettingPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly string $settingKey) {}
}
