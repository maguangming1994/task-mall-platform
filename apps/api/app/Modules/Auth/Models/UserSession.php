<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserSession extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'user_device_id',
        'user_identity_id',
        'refresh_token_hash',
        'issued_at',
        'expires_at',
        'revoked_at',
        'revoke_reason',
    ];

    protected $hidden = [
        'refresh_token_hash',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserSession $session): void {
            $session->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_identity_id');
    }
}
