<?php

namespace App\Modules\Push\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSettlementItem extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'campus_id', 'settlement_id', 'user_id', 'team_id', 'level_code',
        'submission_count', 'commission_cents', 'team_bonus_cents', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_count' => 'integer',
            'commission_cents' => 'integer',
            'team_bonus_cents' => 'integer',
            'paid_at' => 'datetime',
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
