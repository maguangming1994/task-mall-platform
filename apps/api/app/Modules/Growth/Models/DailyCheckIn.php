<?php

namespace App\Modules\Growth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DailyCheckIn extends Model
{
    protected $fillable = [
        'public_id', 'user_id', 'check_in_date', 'streak_days', 'reward_points', 'reward_event_key',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['check_in_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
