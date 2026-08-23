<?php

namespace App\Modules\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityActivityParticipant extends Model
{
    protected $fillable = ['community_activity_id', 'user_id', 'status', 'joined_at', 'left_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
