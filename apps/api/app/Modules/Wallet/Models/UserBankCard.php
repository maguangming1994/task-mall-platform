<?php

namespace App\Modules\Wallet\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserBankCard extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'cardholder_name', 'bank_name',
        'card_tail', 'phone_masked', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
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
