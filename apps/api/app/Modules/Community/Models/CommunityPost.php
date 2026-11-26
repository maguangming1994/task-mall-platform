<?php

namespace App\Modules\Community\Models;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CommunityPost extends Model
{
    use BelongsToCampus, SoftDeletes;

    public const CATEGORIES = [
        'experience' => '经验分享',
        'guide' => '实用攻略',
        'question' => '互助问答',
        'showcase' => '成果展示',
    ];

    public const VISIBILITIES = ['public', 'followers', 'anonymous'];

    protected $fillable = ['public_id', 'campus_id', 'user_id', 'title', 'content', 'category_code', 'location_name', 'visibility', 'location_latitude', 'location_longitude', 'status', 'current_version_no', 'published_version_no', 'comment_count', 'like_count', 'favorite_count', 'reward_granted', 'idempotency_key_hash', 'reviewed_by_admin_id', 'review_reason_code', 'review_note', 'published_at'];

    protected static function booted(): void
    {
        static::creating(fn (CommunityPost $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['current_version_no' => 'integer', 'published_version_no' => 'integer', 'comment_count' => 'integer', 'like_count' => 'integer', 'favorite_count' => 'integer', 'location_latitude' => 'float', 'location_longitude' => 'float', 'reward_granted' => 'boolean', 'published_at' => 'datetime'];
    }

    public function scopeVisibleTo($query, ?User $viewer, bool $followEnabled = true)
    {
        return $query->where(function ($query) use ($viewer, $followEnabled): void {
            $query->whereIn('visibility', ['public', 'anonymous']);
            if ($viewer === null) {
                return;
            }
            $query->orWhere('user_id', $viewer->id);
            if ($followEnabled) {
                $query->orWhere(function ($query) use ($viewer): void {
                    $query->where('visibility', 'followers')
                        ->whereExists(function ($follows) use ($viewer): void {
                            $follows->selectRaw('1')->from('user_follows')
                                ->whereColumn('user_follows.following_id', 'community_posts.user_id')
                                ->where('user_follows.follower_id', $viewer->id);
                        });
                });
            }
        });
    }

    public function isVisibleTo(?User $viewer, bool $followEnabled = true): bool
    {
        if (in_array($this->visibility, ['public', 'anonymous'], true)) {
            return true;
        }
        if ($this->visibility !== 'followers' || $viewer === null) {
            return false;
        }
        if ($this->user_id === $viewer->id) {
            return true;
        }

        return $followEnabled && UserFollow::query()
            ->where('follower_id', $viewer->id)
            ->where('following_id', $this->user_id)
            ->exists();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommunityPostVersion::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
