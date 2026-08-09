<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskSubmission extends Model
{
    protected $fillable = ['public_id', 'task_claim_id', 'user_id', 'submission_no', 'status', 'content_snapshot', 'file_public_ids', 'idempotency_key_hash', 'submitted_at', 'review_started_at', 'review_due_at', 'review_reminded_at', 'withdrawn_at'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskSubmission $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['submission_no' => 'integer', 'content_snapshot' => 'array', 'file_public_ids' => 'array', 'submitted_at' => 'datetime', 'review_started_at' => 'datetime', 'review_due_at' => 'datetime', 'review_reminded_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(TaskClaim::class, 'task_claim_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TaskReview::class);
    }
}
