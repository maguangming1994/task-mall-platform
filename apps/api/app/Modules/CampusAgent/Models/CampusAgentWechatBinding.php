<?php

namespace App\Modules\CampusAgent\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampusAgentWechatBinding extends Model
{
    protected $fillable = [
        'public_id',
        'app_id',
        'openid_hash',
        'admin_user_id',
        'nickname_snapshot',
        'bound_at',
        'last_used_at',
    ];

    protected $hidden = ['openid_hash'];

    protected static function booted(): void
    {
        static::creating(fn (CampusAgentWechatBinding $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'bound_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public static function hashOpenid(string $openid): string
    {
        return hash('sha256', $openid);
    }
}
