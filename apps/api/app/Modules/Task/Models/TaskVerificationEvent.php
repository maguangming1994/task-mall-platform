<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskVerificationEvent extends Model
{
    protected $fillable = ['public_id', 'task_id', 'task_claim_id', 'user_id', 'event_type', 'source', 'event_key_hash', 'payload', 'goal_met', 'status', 'error_message', 'processed_at'];

    protected $hidden = ['event_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskVerificationEvent $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'goal_met' => 'boolean', 'processed_at' => 'datetime'];
    }
}
