<?php

namespace App\Modules\Payment\Models;

use App\Modules\Assets\Models\WalletRecharge;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\Order\Models\Order;
use App\Modules\SecondHand\Models\SecondHandOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = ['public_id', 'payment_no', 'order_id', 'wallet_recharge_id', 'second_hand_order_id', 'express_pickup_order_id', 'user_id', 'channel', 'scene', 'cash_amount', 'status', 'provider_trade_no', 'client_payload', 'idempotency_key_hash', 'expires_at', 'succeeded_at', 'closed_at'];

    protected static function booted(): void
    {
        static::creating(fn (Payment $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['cash_amount' => 'integer', 'client_payload' => 'array', 'expires_at' => 'datetime', 'succeeded_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function walletRecharge(): BelongsTo
    {
        return $this->belongsTo(WalletRecharge::class);
    }

    public function secondHandOrder(): BelongsTo
    {
        return $this->belongsTo(SecondHandOrder::class);
    }

    public function expressPickupOrder(): BelongsTo
    {
        return $this->belongsTo(ExpressPickupOrder::class);
    }
}
