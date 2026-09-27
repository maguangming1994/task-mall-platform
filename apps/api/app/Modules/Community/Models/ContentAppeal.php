<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContentAppeal extends Model
{
    protected $fillable = ['public_id', 'moderation_action_id', 'user_id', 'reason', 'evidence_file_ids', 'status', 'idempotency_key_hash', 'reviewed_by_admin_id', 'review_note', 'reviewed_at'];

    protected static function booted(): void
    {
        static::creating(fn (ContentAppeal $model) => $model->public_id ??= (string) Str::ulid());
        static::addGlobalScope('admin_campus_content', function (Builder $builder): void {
            if (! app()->bound('request')) {
                return;
            }

            $request = request();
            $campusId = $request->attributes->get('campus_database_id');
            if ($request->attributes->get('campus_context_strict') === true && is_int($campusId)) {
                $builder->whereHas('moderationAction');
            }
        });
    }

    protected function casts(): array
    {
        return ['evidence_file_ids' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function moderationAction(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class);
    }
}
