<?php

namespace App\Modules\Community\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommunityActivity extends Model
{
    use BelongsToCampus;

    public const CATEGORIES = [
        'study' => '自习',
        'movie' => '电影',
        'dining' => '聚餐',
        'carpool' => '拼车',
        'group_buy' => '拼单',
        'gaming' => '游戏',
        'sports' => '运动',
        'travel' => '旅行',
        'other' => '其他',
    ];

    protected $fillable = [
        'public_id', 'campus_id', 'creator_user_id', 'category_code', 'title', 'details', 'location', 'starts_at',
        'participant_limit', 'participant_count', 'status', 'idempotency_key_hash',
        'reviewed_by_admin_id', 'review_reason_code', 'review_note', 'reviewed_at', 'closed_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CommunityActivity $activity) => $activity->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'participant_limit' => 'integer',
            'participant_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CommunityActivityParticipant::class);
    }
}
