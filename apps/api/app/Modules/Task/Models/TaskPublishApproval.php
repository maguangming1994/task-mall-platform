<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskPublishApproval extends Model
{
    protected $fillable = [
        'public_id', 'task_id', 'task_version_id', 'requested_by_admin_id', 'status',
        'triggered_rules', 'threshold_snapshot', 'reviewed_by_admin_id', 'review_note',
        'review_idempotency_key_hash', 'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (TaskPublishApproval $approval) => $approval->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'triggered_rules' => 'array',
            'threshold_snapshot' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskVersion(): BelongsTo
    {
        return $this->belongsTo(TaskVersion::class);
    }
}
