<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RewardBudgetTransaction extends Model
{
    protected $fillable = ['public_id', 'reward_budget_pool_id', 'task_id', 'task_reward_budget_id', 'transaction_type', 'change_amount', 'total_after', 'locked_after', 'issued_after', 'idempotency_key', 'admin_user_id', 'reason', 'request_id'];

    protected static function booted(): void
    {
        static::creating(fn (RewardBudgetTransaction $transaction) => $transaction->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new \LogicException('Budget transactions are append only.'));
        static::deleting(fn () => throw new \LogicException('Budget transactions are append only.'));
    }
}
