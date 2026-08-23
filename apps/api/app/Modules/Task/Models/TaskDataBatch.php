<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskDataBatch extends Model
{
    protected $fillable = ['public_id', 'name', 'original_filename', 'source_format', 'source_headers', 'field_mapping', 'row_count', 'valid_count', 'invalid_count', 'status', 'snapshot_hash', 'task_id', 'task_version_id', 'created_by_admin_id', 'locked_at'];

    protected static function booted(): void
    {
        static::creating(fn (TaskDataBatch $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(function (TaskDataBatch $batch): void {
            if ($batch->getOriginal('locked_at') !== null && $batch->isDirty(['source_headers', 'field_mapping', 'row_count', 'valid_count', 'invalid_count', 'snapshot_hash'])) {
                throw new \LogicException('Published task data batch snapshot is immutable.');
            }
        });
        static::deleting(function (TaskDataBatch $batch): void {
            if ($batch->locked_at !== null) {
                throw new \LogicException('Published task data batches cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'field_mapping' => 'array', 'row_count' => 'integer', 'valid_count' => 'integer', 'invalid_count' => 'integer', 'locked_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskDataItem::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TaskVersion::class, 'task_version_id');
    }
}
