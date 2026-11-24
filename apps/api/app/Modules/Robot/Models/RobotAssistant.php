<?php

namespace App\Modules\Robot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RobotAssistant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'remark',
        'enabled',
        'app_id',
        'app_secret',
        'robot_bindings',
        'webhook_last_tested_at',
        'webhook_last_test_status',
        'webhook_last_test_message',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected $hidden = ['app_secret'];

    protected static function booted(): void
    {
        static::creating(fn (RobotAssistant $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'app_secret' => 'encrypted',
            'robot_bindings' => 'array',
            'webhook_last_tested_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
