<?php

namespace App\Modules\Push\Models;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PushTeam extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'name', 'slogan', 'intro', 'leader_user_id', 'level_code',
        'status', 'settlement_cycle', 'review_hours', 'member_limit', 'member_count',
        'stats_month', 'month_orders', 'month_commission_cents', 'created_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PushTeam $team) => $team->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'month_orders' => 'integer',
            'month_commission_cents' => 'integer',
            'review_hours' => 'integer',
            'member_limit' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(PushTeamMember::class, 'team_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PushTeamApplication::class, 'team_id');
    }
}
