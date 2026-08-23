<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskClaim extends Model
{
    protected $fillable = ['public_id', 'task_id', 'task_version_id', 'user_id', 'enrollment_source', 'cycle_key', 'cycle_starts_at', 'cycle_ends_at', 'task_snapshot', 'status', 'execution_status', 'review_status', 'slot_status', 'progress_started_at', 'progress_current', 'progress_target', 'last_progress_at', 'completion_deadline_at', 'cancel_count', 'submission_count', 'appeal_count', 'reward_status', 'claim_idempotency_key_hash', 'approved_at', 'cancelled_at'];

    protected $hidden = ['claim_idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskClaim $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['task_snapshot' => 'array', 'cycle_starts_at' => 'datetime', 'cycle_ends_at' => 'datetime', 'progress_started_at' => 'datetime', 'progress_current' => 'integer', 'progress_target' => 'integer', 'last_progress_at' => 'datetime', 'completion_deadline_at' => 'datetime', 'cancel_count' => 'integer', 'submission_count' => 'integer', 'appeal_count' => 'integer', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(TaskRewardReservation::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(TaskSubmission::class);
    }

    public function rewardDeliveries(): HasMany
    {
        return $this->hasMany(TaskRewardDelivery::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TaskReview::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(TaskAppeal::class);
    }
}
