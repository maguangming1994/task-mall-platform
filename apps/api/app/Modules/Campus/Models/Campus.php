<?php

namespace App\Modules\Campus\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Campus extends Model
{
    protected $fillable = [
        'public_id', 'code', 'official_school_code', 'name', 'catalog_type',
        'education_level', 'supervising_authority', 'official_note', 'catalog_status',
        'catalog_version', 'catalog_source_url', 'catalog_synced_at', 'province', 'city',
        'district', 'address', 'latitude', 'longitude', 'status', 'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (Campus $campus): void {
            $campus->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'sort_order' => 'integer',
            'catalog_synced_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'admin_user_campus')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
