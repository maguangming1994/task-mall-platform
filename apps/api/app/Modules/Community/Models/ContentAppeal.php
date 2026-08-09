<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContentAppeal extends Model
{
    protected $fillable = ['public_id', 'moderation_action_id', 'user_id', 'reason', 'evidence_file_ids', 'status', 'idempotency_key_hash', 'reviewed_by_admin_id', 'review_note', 'reviewed_at'];

    protected static function booted(): void
    {
        static::creating(fn (ContentAppeal $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['evidence_file_ids' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
