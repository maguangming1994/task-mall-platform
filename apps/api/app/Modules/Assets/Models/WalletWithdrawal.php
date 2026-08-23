<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletWithdrawal extends Model
{
    protected $fillable = ['public_id', 'withdrawal_no', 'user_id', 'requested_amount_cents', 'fee_rate_ppm', 'fee_amount_cents', 'received_amount_cents', 'channel', 'status', 'idempotency_key_hash', 'request_payload_hash', 'reviewed_by_admin_id', 'review_note', 'review_idempotency_key_hash', 'channel_reference', 'failure_message', 'reviewed_at', 'completed_at'];

    protected $hidden = ['idempotency_key_hash', 'request_payload_hash', 'review_idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (WalletWithdrawal $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['requested_amount_cents' => 'integer', 'fee_rate_ppm' => 'integer', 'fee_amount_cents' => 'integer', 'received_amount_cents' => 'integer', 'reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
