<?php

namespace App\Modules\Robot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RobotForwardConfig extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'description',
        'enabled',
        'filter_rules',
        'template_config',
        'robot_targets',
        'last_forwarded_post_id',
        'last_forwarded_at',
        'last_dispatch_at',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (RobotForwardConfig $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'filter_rules' => 'array',
            'template_config' => 'array',
            'robot_targets' => 'array',
            'last_forwarded_post_id' => 'integer',
            'last_forwarded_at' => 'datetime',
            'last_dispatch_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RobotMessageRequest::class, 'forward_config_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
