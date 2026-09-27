<?php

namespace App\Modules\Task\Models;

use App\Modules\Campus\Models\Concerns\ScopesAdminCampusThroughRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskAppeal extends Model
{
    use ScopesAdminCampusThroughRelation;

    protected $fillable = ['public_id', 'task_claim_id', 'task_submission_id', 'appeal_no', 'user_id', 'status', 'reason', 'evidence_file_public_ids', 'original_reviewer_admin_id', 'reviewer_admin_id', 'decision', 'result_reason', 'create_idempotency_key_hash', 'review_idempotency_key_hash', 'appeal_deadline_at', 'reviewed_at'];

    protected $hidden = ['create_idempotency_key_hash', 'review_idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskAppeal $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['appeal_no' => 'integer', 'evidence_file_public_ids' => 'array', 'appeal_deadline_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(TaskClaim::class, 'task_claim_id');
    }

    protected static function adminCampusRelation(): string
    {
        return 'claim.task';
    }
}
