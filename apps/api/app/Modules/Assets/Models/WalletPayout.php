<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletPayout extends Model
{
    protected $fillable = ['public_id', 'wallet_withdrawal_id', 'payout_no', 'provider', 'amount_cents', 'status', 'provider_reference', 'retry_count', 'last_error', 'submitted_at', 'last_queried_at', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (WalletPayout $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'retry_count' => 'integer', 'submitted_at' => 'datetime', 'last_queried_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(WalletWithdrawal::class, 'wallet_withdrawal_id');
    }
}
