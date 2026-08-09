<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskVersion extends Model
{
    protected $fillable = ['task_id', 'version_no', 'status', 'change_type', 'source_version_no', 'changed_fields', 'content', 'created_by_admin_id', 'published_at'];

    protected static function booted(): void
    {
        static::updating(function (TaskVersion $version): void {
            if ($version->isDirty(['version_no', 'change_type', 'source_version_no', 'changed_fields', 'content', 'created_by_admin_id'])) {
                throw new \LogicException('Task version content is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Task versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'content' => 'array', 'published_at' => 'datetime', 'version_no' => 'integer', 'source_version_no' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
