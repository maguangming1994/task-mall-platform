<?php

namespace App\Modules\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfileVisit extends Model
{
    protected $fillable = ['profile_user_id', 'visitor_user_id', 'visit_count', 'last_visited_at'];

    protected function casts(): array
    {
        return ['visit_count' => 'integer', 'last_visited_at' => 'datetime'];
    }

    public function profileUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_user_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_user_id');
    }
}
