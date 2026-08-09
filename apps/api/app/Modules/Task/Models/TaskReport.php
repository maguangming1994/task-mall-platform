<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskReport extends Model
{
    protected $fillable = ['public_id', 'task_id', 'reporter_user_id', 'task_risk_case_id', 'reason_code', 'description', 'evidence_file_public_ids', 'status', 'idempotency_key_hash'];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskReport $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['evidence_file_public_ids' => 'array'];
    }
}
