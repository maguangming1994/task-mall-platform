<?php

namespace App\Modules\ExpressPickup\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExpressPickupRunner extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'user_id', 'status', 'real_name', 'phone',
        'applied_at', 'reviewed_at', 'reviewed_by_admin_id', 'reject_reason',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $runner) => $runner->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
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
