<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserIdentityVerification extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'provider', 'status', 'real_name_masked',
        'identity_number_masked', 'identity_number_hash', 'provider_request_id',
        'failure_code', 'verified_at',
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

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
