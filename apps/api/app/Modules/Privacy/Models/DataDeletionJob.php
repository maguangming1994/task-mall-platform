<?php

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DataDeletionJob extends Model
{
    protected $fillable = ['public_id', 'user_id', 'privacy_request_id', 'cancellation_request_id', 'job_type', 'status', 'idempotency_key', 'attempts', 'stats', 'last_error', 'run_after', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (self $job) => $job->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['stats' => 'array', 'run_after' => 'datetime', 'completed_at' => 'datetime'];
    }
}
