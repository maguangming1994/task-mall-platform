<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Account\Models\UserAssetAccount;
use App\Modules\Auth\Models\IdentityConflict;
use App\Modules\Auth\Models\UserDevice;
use App\Modules\Auth\Models\UserIdentity;
use App\Modules\Auth\Models\UserSession;
use App\Modules\Campus\Models\Campus;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Notification\Models\NotificationPreference;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'public_id',
        'campus_id',
        'nickname',
        'avatar_file_id',
        'phone',
        'phone_hash',
        'phone_verified_at',
        'profile_completed_at',
        'status',
        'cancelled_at',
        'merged_into_user_id',
    ];

    protected $hidden = [
        'phone',
        'phone_hash',
        'merged_into_user_id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('admin_campus', function (Builder $builder): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();
            $campusId = $request->attributes->get('campus_database_id');
            if ($request->attributes->get('campus_context_strict') === true && is_int($campusId)) {
                $builder->where($builder->getModel()->qualifyColumn('campus_id'), $campusId);
            }
        });

        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::ulid();
            $campusId = app()->bound('request') ? request()->attributes->get('campus_database_id') : null;
            if ($user->campus_id === null && is_int($campusId)) {
                $user->campus_id = $campusId;
            }
        });
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function identityConflicts(): HasMany
    {
        return $this->hasMany(IdentityConflict::class, 'source_user_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function assetAccount(): HasOne
    {
        return $this->hasOne(UserAssetAccount::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function identityVerification(): HasOne
    {
        return $this->hasOne(UserIdentityVerification::class)->latestOfMany();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'phone_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
