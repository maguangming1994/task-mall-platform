<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CommunityPost extends Model
{
    use SoftDeletes;

    protected $fillable = ['public_id', 'user_id', 'title', 'content', 'category_code', 'status', 'current_version_no', 'published_version_no', 'comment_count', 'like_count', 'reward_granted', 'idempotency_key_hash', 'reviewed_by_admin_id', 'review_reason_code', 'review_note', 'published_at'];

    protected static function booted(): void
    {
        static::creating(fn (CommunityPost $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['current_version_no' => 'integer', 'published_version_no' => 'integer', 'comment_count' => 'integer', 'like_count' => 'integer', 'reward_granted' => 'boolean', 'published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommunityPostVersion::class);
    }
}
