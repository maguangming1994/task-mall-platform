<?php

namespace App\Modules\Privacy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccountCancellationRequest extends Model
{
    protected $fillable = ['public_id', 'user_id', 'status', 'eligibility_snapshot', 'forfeit_points_and_coupons', 'idempotency_key_hash', 'cooling_off_ends_at', 'cancelled_at', 'cancel_reason', 'executed_at'];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['eligibility_snapshot' => 'array', 'forfeit_points_and_coupons' => 'boolean', 'cooling_off_ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'executed_at' => 'datetime'];
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
