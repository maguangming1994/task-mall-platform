<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminMfaChallenge extends Model
{
    protected $fillable = ['public_id', 'admin_user_id', 'challenge_token_hash', 'attempts', 'expires_at', 'consumed_at'];

    protected $hidden = ['challenge_token_hash'];

    protected static function booted(): void
    {
        static::creating(fn (AdminMfaChallenge $challenge) => $challenge->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
