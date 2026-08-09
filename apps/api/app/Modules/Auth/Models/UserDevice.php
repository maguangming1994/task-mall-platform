<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UserDevice extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'device_fingerprint_hash',
        'client_platform',
        'device_name',
        'last_active_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserDevice $device): void {
            $device->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }
}
