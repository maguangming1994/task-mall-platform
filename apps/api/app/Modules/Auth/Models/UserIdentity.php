<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    public const WECHAT_PROVIDERS = [
        'wechat_mini',
        'wechat_oa',
        'wechat_miniprogram',
        'wechat_official_account',
    ];

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_user_value',
        'union_id',
        'union_value',
        'metadata',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'provider_user_value' => 'encrypted',
            'union_value' => 'encrypted',
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isWechat(): bool
    {
        return in_array($this->provider, self::WECHAT_PROVIDERS, true);
    }
}
