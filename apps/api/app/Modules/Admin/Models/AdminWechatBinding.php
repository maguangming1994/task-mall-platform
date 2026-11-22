<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminWechatBinding extends Model
{
    protected $fillable = [
        'public_id',
        'admin_user_id',
        'openid_hash',
        'app_id_hash',
        'bound_at',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = ['openid_hash', 'app_id_hash'];

    protected static function booted(): void
    {
        static::creating(function (AdminWechatBinding $binding): void {
            $binding->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'bound_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
