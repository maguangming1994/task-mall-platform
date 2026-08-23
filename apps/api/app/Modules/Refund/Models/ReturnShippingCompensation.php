<?php

namespace App\Modules\Refund\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReturnShippingCompensation extends Model
{
    protected $table = 'return_shipping_compensations';

    protected $fillable = ['public_id', 'user_id', 'source_type', 'source_id', 'requested_amount', 'approved_amount', 'status', 'carrier_code', 'tracking_no', 'payment_proof_file_id', 'reviewed_by_admin_id', 'review_note', 'review_idempotency_key_hash', 'paid_at'];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['requested_amount' => 'integer', 'approved_amount' => 'integer', 'paid_at' => 'datetime'];
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
