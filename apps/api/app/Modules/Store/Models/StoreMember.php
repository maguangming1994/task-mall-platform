<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StoreMember extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_STAFF = 'staff';

    protected $fillable = ['public_id', 'store_id', 'user_id', 'role', 'status', 'joined_at'];

    protected $attributes = ['role' => self::ROLE_STAFF, 'status' => 'active'];

    protected static function booted(): void
    {
        static::creating(function (self $member): void {
            $member->public_id ??= (string) Str::ulid();
            $member->joined_at ??= now();
        });
    }

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canManage(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_MANAGER], true);
    }
}
