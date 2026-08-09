<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IdentityConflict extends Model
{
    protected $fillable = [
        'public_id',
        'kind',
        'provider',
        'provider_user_id_hash',
        'provider_user_value',
        'union_id_hash',
        'union_value',
        'source_user_id',
        'target_user_id',
        'candidate_user_ids',
        'status',
        'resolved_by_admin_id',
        'resolution',
        'resolution_reason',
        'resolved_at',
    ];

    protected $hidden = [
        'provider_user_id_hash',
        'provider_user_value',
        'union_id_hash',
        'union_value',
        'candidate_user_ids',
    ];

    protected static function booted(): void
    {
        static::creating(function (IdentityConflict $conflict): void {
            $conflict->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'provider_user_value' => 'encrypted',
            'union_value' => 'encrypted',
            'candidate_user_ids' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'resolved_by_admin_id');
    }
}
