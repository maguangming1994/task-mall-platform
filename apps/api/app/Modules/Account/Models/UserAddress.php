<?php

namespace App\Modules\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class UserAddress extends Model
{
    use SoftDeletes;

    protected $fillable = ['public_id', 'user_id', 'recipient_name', 'phone', 'province', 'city', 'district', 'detail', 'postal_code', 'is_default', 'idempotency_key_hash'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (UserAddress $address) => $address->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['recipient_name' => 'encrypted', 'phone' => 'encrypted', 'detail' => 'encrypted', 'is_default' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
