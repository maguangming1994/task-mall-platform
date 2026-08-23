<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Task extends Model
{
    protected $fillable = ['public_id', 'created_by_admin_id', 'creation_channel', 'source_task_template_id', 'source_task_template_version_no', 'status', 'current_version_no', 'published_version_no', 'lock_version', 'view_count', 'idempotency_key_hash', 'scheduled_at', 'published_at', 'closed_at'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (Task $task) => $task->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'published_at' => 'datetime', 'closed_at' => 'datetime', 'source_task_template_version_no' => 'integer', 'current_version_no' => 'integer', 'published_version_no' => 'integer', 'lock_version' => 'integer', 'view_count' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaskVersion::class);
    }

    public function rewardBudgets(): HasMany
    {
        return $this->hasMany(TaskRewardBudget::class);
    }

    public function publishApprovals(): HasMany
    {
        return $this->hasMany(TaskPublishApproval::class);
    }
}
