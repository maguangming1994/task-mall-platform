<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminSession extends Model
{
    protected $fillable = [
        'public_id',
        'admin_user_id',
        'refresh_token_hash',
        'issued_at',
        'last_active_at',
        'verified_at',
        'idle_expires_at',
        'expires_at',
        'revoked_at',
        'revoke_reason',
        'ip_hash',
        'user_agent_hash',
    ];

    protected $hidden = ['refresh_token_hash'];

    protected static function booted(): void
    {
        static::creating(function (AdminSession $session): void {
            $session->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'last_active_at' => 'datetime',
            'verified_at' => 'datetime',
            'idle_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
