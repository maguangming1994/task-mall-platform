<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskDataItem extends Model
{
    protected $fillable = ['public_id', 'task_data_batch_id', 'row_no', 'source_payload', 'mapped_payload', 'validation_errors', 'status', 'allocated_task_claim_id', 'allocated_user_id', 'allocated_at', 'release_count', 'last_released_at'];

    protected static function booted(): void
    {
        static::creating(fn (TaskDataItem $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(function (TaskDataItem $item): void {
            if ($item->batch()->whereNotNull('locked_at')->exists() && $item->isDirty(['source_payload', 'mapped_payload', 'validation_errors', 'row_no'])) {
                throw new \LogicException('Published task data item content is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['source_payload' => 'array', 'mapped_payload' => 'array', 'validation_errors' => 'array', 'row_no' => 'integer', 'release_count' => 'integer', 'allocated_at' => 'datetime', 'last_released_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TaskDataBatch::class, 'task_data_batch_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(TaskClaim::class, 'allocated_task_claim_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_user_id');
    }
}
