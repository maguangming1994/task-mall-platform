<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CommunityComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['public_id', 'community_post_id', 'user_id', 'parent_comment_id', 'content', 'status', 'reward_granted', 'rule_snapshot', 'idempotency_key_hash', 'reviewed_by_admin_id', 'review_reason_code', 'review_note', 'published_at'];

    protected static function booted(): void
    {
        static::creating(fn (CommunityComment $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['reward_granted' => 'boolean', 'rule_snapshot' => 'array', 'published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
