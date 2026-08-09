<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MediaStorageMigration extends Model
{
    protected $fillable = ['public_id', 'from_provider', 'to_provider', 'status', 'total_assets', 'processed_assets', 'failed_assets', 'delete_source', 'error_summary', 'requested_by_admin_id', 'started_at', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (MediaStorageMigration $migration) => $migration->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['total_assets' => 'integer', 'processed_assets' => 'integer', 'failed_assets' => 'integer', 'delete_source' => 'boolean', 'error_summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
