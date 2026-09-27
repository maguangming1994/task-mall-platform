<?php

namespace App\Modules\Community\Models;

use App\Modules\Campus\Models\Concerns\ScopesAdminCampusCommunityContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ModerationAction extends Model
{
    use ScopesAdminCampusCommunityContent;

    protected $fillable = ['public_id', 'content_type', 'content_id', 'content_author_user_id', 'action_type', 'reason_code', 'note', 'previous_status', 'reward_revoked', 'status', 'admin_user_id', 'idempotency_key_hash', 'appeal_deadline_at', 'reversed_at'];

    protected static function booted(): void
    {
        static::creating(fn (ModerationAction $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['reward_revoked' => 'boolean', 'appeal_deadline_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
