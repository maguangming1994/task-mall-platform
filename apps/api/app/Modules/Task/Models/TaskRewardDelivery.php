<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskRewardDelivery extends Model
{
    protected $fillable = ['public_id', 'task_claim_id', 'task_reward_reservation_id', 'asset_type', 'amount', 'reward_snapshot', 'status', 'attempt_count', 'last_error_code', 'last_error_message', 'last_attempted_at', 'next_retry_at', 'delivered_at'];

    protected static function booted(): void
    {
        static::creating(fn (TaskRewardDelivery $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'reward_snapshot' => 'array', 'attempt_count' => 'integer', 'last_attempted_at' => 'datetime', 'next_retry_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(TaskClaim::class, 'task_claim_id');
    }
}
