<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserIdentityVerification extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'provider', 'status', 'real_name_masked',
        'identity_number_masked', 'identity_number_hash', 'provider_request_id',
        'failure_code', 'verified_at', 'reviewed_by_admin_id', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['identity_number_hash'];

    protected static function booted(): void
    {
        static::creating(function (UserIdentityVerification $verification): void {
            $verification->public_id ??= (string) Str::ulid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 执行人工审核的管理员 */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
