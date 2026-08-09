<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskRiskCase extends Model
{
    protected $fillable = ['public_id', 'task_id', 'task_claim_id', 'source', 'risk_level', 'status', 'reward_frozen', 'assigned_admin_id', 'conclusion', 'response_due_at', 'resolved_at'];

    protected static function booted(): void
    {
        static::creating(fn (TaskRiskCase $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['reward_frozen' => 'boolean', 'response_due_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
