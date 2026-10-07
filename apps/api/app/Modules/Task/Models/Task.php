<?php

namespace App\Modules\Task\Models;

use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Task extends Model
{
    use BelongsToCampus;

    protected $fillable = ['public_id', 'campus_id', 'is_platform_wide', 'created_by_admin_id', 'creation_channel', 'source_task_template_id', 'source_task_template_version_no', 'status', 'current_version_no', 'published_version_no', 'lock_version', 'view_count', 'idempotency_key_hash', 'scheduled_at', 'published_at', 'closed_at'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (Task $task) => $task->public_id ??= (string) Str::ulid());
    }

    /**
     * strict 校区下，平台投放任务（is_platform_wide=true）可跨校可见（D3 显式豁免）。
     */
    protected function applyCampusScope(Builder $builder, ?int $campusId): void
    {
        if ($campusId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }
        $builder->where(function ($q) use ($campusId) {
            $q->where('campus_id', $campusId)->orWhere('is_platform_wide', true);
        });
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
