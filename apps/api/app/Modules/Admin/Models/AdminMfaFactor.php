<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminMfaFactor extends Model
{
    protected $fillable = ['public_id', 'admin_user_id', 'type', 'secret_ciphertext', 'status', 'confirmed_at', 'last_used_at'];

    protected $hidden = ['secret_ciphertext'];

    protected static function booted(): void
    {
        static::creating(fn (AdminMfaFactor $factor) => $factor->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
