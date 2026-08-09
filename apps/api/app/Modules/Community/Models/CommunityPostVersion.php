<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommunityPostVersion extends Model
{
    protected $fillable = ['public_id', 'community_post_id', 'version_no', 'title', 'content', 'review_status', 'rule_snapshot', 'reviewed_by_admin_id', 'review_reason_code', 'review_note', 'reviewed_at'];

    protected static function booted(): void
    {
        static::creating(fn (CommunityPostVersion $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'rule_snapshot' => 'array', 'reviewed_at' => 'datetime'];
    }
}
