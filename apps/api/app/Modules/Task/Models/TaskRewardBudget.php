<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskRewardBudget extends Model
{
    protected $fillable = ['public_id', 'task_id', 'task_version_id', 'reward_budget_pool_id', 'reward_sequence', 'asset_type', 'unit_amount', 'max_deliveries_total', 'max_deliveries_per_cycle', 'locked_amount', 'reserved_amount', 'issued_amount', 'released_amount', 'recovered_amount', 'status', 'version'];

    protected static function booted(): void
    {
        static::creating(fn (TaskRewardBudget $budget) => $budget->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'reward_sequence' => 'integer', 'unit_amount' => 'integer', 'max_deliveries_total' => 'integer', 'max_deliveries_per_cycle' => 'integer',
            'locked_amount' => 'integer', 'reserved_amount' => 'integer', 'issued_amount' => 'integer', 'released_amount' => 'integer', 'recovered_amount' => 'integer', 'version' => 'integer',
        ];
    }
}
