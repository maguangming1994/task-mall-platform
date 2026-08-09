<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskPublisherSession extends Model
{
    protected $fillable = ['public_id', 'task_publisher_id', 'token_hash', 'last_active_at', 'expires_at', 'revoked_at', 'ip_hash', 'user_agent_hash'];

    protected $hidden = ['token_hash', 'ip_hash', 'user_agent_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskPublisherSession $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(TaskPublisher::class, 'task_publisher_id');
    }
}
