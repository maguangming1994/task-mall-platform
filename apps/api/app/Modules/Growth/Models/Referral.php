<?php

namespace App\Modules\Growth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Referral extends Model
{
    protected $fillable = ['public_id', 'referrer_user_id', 'referred_user_id', 'referral_code', 'bound_at', 'first_task_claim_id', 'first_task_completed_at', 'qualifying_order_public_id', 'first_cash_order_completed_at', 'status', 'qualified_at', 'reward_event_key', 'rewarded_at', 'revoked_at'];

    protected static function booted(): void
    {
        static::creating(fn (Referral $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['bound_at' => 'datetime', 'first_task_completed_at' => 'datetime', 'first_cash_order_completed_at' => 'datetime', 'qualified_at' => 'datetime', 'rewarded_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
