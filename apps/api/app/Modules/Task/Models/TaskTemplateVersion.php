<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTemplateVersion extends Model
{
    protected $fillable = ['task_template_id', 'version_no', 'source_version_no', 'changed_fields', 'content', 'created_by_admin_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Task template versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Task template versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'source_version_no' => 'integer', 'changed_fields' => 'array', 'content' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }
}
