<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskStepReview extends Model
{
    protected $fillable = ['public_id', 'task_review_id', 'task_submission_id', 'step_id', 'step_title', 'decision', 'reason_code', 'note', 'evidence_file_public_ids'];

    protected static function booted(): void
    {
        static::creating(fn (TaskStepReview $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['evidence_file_public_ids' => 'array'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(TaskReview::class, 'task_review_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'task_submission_id');
    }
}
