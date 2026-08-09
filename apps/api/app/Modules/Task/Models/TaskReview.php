<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskReview extends Model
{
    protected $fillable = ['public_id', 'task_submission_id', 'task_claim_id', 'reviewer_admin_id', 'decision', 'reason_code', 'note', 'review_snapshot', 'idempotency_key_hash', 'reviewed_at'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskReview $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['review_snapshot' => 'array', 'reviewed_at' => 'datetime'];
    }
}
