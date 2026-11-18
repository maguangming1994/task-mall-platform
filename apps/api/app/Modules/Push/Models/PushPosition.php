<?php

namespace App\Modules\Push\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PushPosition extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'task_id', 'user_id', 'team_id', 'keyword',
        'status', 'claimed_at', 'expires_at', 'closed_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PushPosition $position) => $position->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'expires_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(PushTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
