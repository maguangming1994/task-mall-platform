<?php

namespace App\Modules\Push\Models;

use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PushTask extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'title', 'description', 'cover_text', 'reward_cents',
        'quota', 'claimed_count', 'per_user_limit', 'starts_at', 'ends_at', 'status', 'created_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PushTask $task) => $task->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'reward_cents' => 'integer',
            'quota' => 'integer',
            'claimed_count' => 'integer',
            'per_user_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PushPosition::class, 'task_id');
    }
}
