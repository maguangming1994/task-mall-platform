<?php

namespace App\Modules\Push\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushTeamMember extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'campus_id', 'team_id', 'user_id', 'role', 'level_code', 'status',
        'stats_month', 'month_orders', 'month_commission_cents', 'joined_at', 'left_at',
    ];

    protected function casts(): array
    {
        return [
            'month_orders' => 'integer',
            'month_commission_cents' => 'integer',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(PushTeam::class, 'team_id');
    }
}
