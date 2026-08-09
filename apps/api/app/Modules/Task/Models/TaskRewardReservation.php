<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskRewardReservation extends Model
{
    protected $fillable = ['public_id', 'task_claim_id', 'task_reward_budget_id', 'reward_sequence', 'asset_type', 'amount', 'status', 'idempotency_key_hash', 'reserved_at', 'settled_at'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskRewardReservation $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['reward_sequence' => 'integer', 'amount' => 'integer', 'reserved_at' => 'datetime', 'settled_at' => 'datetime'];
    }
}
