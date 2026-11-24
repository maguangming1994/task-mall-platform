<?php

namespace App\Modules\Robot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RobotPostTemplate extends Model
{
    protected $fillable = ['public_id', 'robot_post_bot_id', 'title', 'content', 'enabled', 'sort', 'used_at'];

    protected static function booted(): void
    {
        static::creating(fn (RobotPostTemplate $template) => $template->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort' => 'integer', 'used_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(RobotPostBot::class, 'robot_post_bot_id');
    }
}
