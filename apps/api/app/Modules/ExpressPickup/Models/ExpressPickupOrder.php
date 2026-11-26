<?php

namespace App\Modules\ExpressPickup\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ExpressPickupOrder extends Model
{
    use BelongsToCampus;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PENDING_ACCEPT = 'pending_accept';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRINT_STATUS_PENDING = 'pending';

    public const PRINT_STATUS_PRINTED = 'printed';

    public const PRINT_STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id', 'order_no', 'campus_id', 'user_id', 'runner_user_id', 'status',
        'pickup_site', 'pickup_code', 'package_info', 'package_count',
        'deliver_building', 'deliver_address', 'contact_name', 'contact_phone',
        'reward_cents', 'total_cents', 'payment_method', 'paid_at', 'paid_amount_cents',
        'expected_at', 'remark', 'idempotency_key_hash',
        'accepted_at', 'picked_up_at', 'delivered_at', 'completed_at',
        'cancelled_at', 'cancel_reason', 'refunded_at', 'settled_at', 'settlement_id',
        'printed_at', 'print_status',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $order) => $order->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'package_count' => 'integer',
            'reward_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_amount_cents' => 'integer',
            'expected_at' => 'datetime',
            'paid_at' => 'datetime',
            'accepted_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'settled_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'runner_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExpressPickupOrderEvent::class, 'order_id')->latest('id');
    }
}
