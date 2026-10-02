<?php

namespace App\Modules\SecondHand\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SecondHandRefund extends Model
{
    protected $fillable = [
        'public_id', 'order_id', 'buyer_user_id', 'seller_user_id',
        'amount', 'reason', 'images', 'status',
        'seller_note', 'seller_responded_at', 'admin_note', 'admin_user_id',
        'arbitrated_at', 'auto_approve_at',
    ];

    protected $casts = [
        'images' => 'array',
        'amount' => 'integer',
        'seller_responded_at' => 'datetime',
        'arbitrated_at' => 'datetime',
        'auto_approve_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SecondHandOrder::class, 'order_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
