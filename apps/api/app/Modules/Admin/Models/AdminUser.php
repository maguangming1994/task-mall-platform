<?php

namespace App\Modules\Admin\Models;

use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class AdminUser extends Authenticatable
{
    protected $fillable = [
        'public_id',
        'account',
        'display_name',
        'email',
        'password',
        'status',
        'requires_mfa',
        'last_login_at',
        'last_verified_at',
    ];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(function (AdminUser $adminUser): void {
            $adminUser->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'requires_mfa' => 'boolean',
            'last_login_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_user_role');
    }

    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'admin_user_campus')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AdminSession::class);
    }

    public function mfaFactor(): HasOne
    {
        return $this->hasOne(AdminMfaFactor::class);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('code', $permission))
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function permissionCodes(): array
    {
        return $this->roles()
            ->with('permissions:id,code')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
