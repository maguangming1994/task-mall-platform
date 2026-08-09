<?php

namespace App\Modules\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMilestone extends Model
{
    protected $fillable = ['user_id', 'milestone_code', 'reward_status', 'achieved_at', 'rewarded_at'];

    protected function casts(): array
    {
        return ['achieved_at' => 'datetime', 'rewarded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
