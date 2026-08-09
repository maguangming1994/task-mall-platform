<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContentReport extends Model
{
    protected $fillable = ['public_id', 'reporter_user_id', 'content_type', 'content_id', 'reason_code', 'description', 'evidence_file_ids', 'status', 'idempotency_key_hash', 'resolved_at'];

    protected static function booted(): void
    {
        static::creating(fn (ContentReport $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['evidence_file_ids' => 'array', 'resolved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
