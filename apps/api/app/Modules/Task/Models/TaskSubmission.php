<?php

namespace App\Modules\Task\Models;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Concerns\ScopesAdminCampusThroughRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskSubmission extends Model
{
    use ScopesAdminCampusThroughRelation;

    protected $fillable = ['public_id', 'task_claim_id', 'user_id', 'submission_no', 'status', 'reviewer_admin_id', 'review_assigned_at', 'review_lock_expires_at', 'content_snapshot', 'file_public_ids', 'revision_step_ids', 'supersedes_submission_id', 'idempotency_key_hash', 'submitted_at', 'review_started_at', 'review_due_at', 'review_reminded_at', 'withdrawn_at'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskSubmission $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['submission_no' => 'integer', 'content_snapshot' => 'array', 'file_public_ids' => 'array', 'revision_step_ids' => 'array', 'submitted_at' => 'datetime', 'review_assigned_at' => 'datetime', 'review_lock_expires_at' => 'datetime', 'review_started_at' => 'datetime', 'review_due_at' => 'datetime', 'review_reminded_at' => 'datetime', 'withdrawn_at' => 'datetime'];
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_admin_id');
    }

    public function supersededSubmission(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_submission_id');
    }

    protected static function adminCampusRelation(): string
    {
        return 'claim.task';
    }
}
