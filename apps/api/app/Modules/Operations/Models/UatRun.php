<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class UatRun extends Model
{
    protected $fillable = ['public_id', 'release_version', 'environment', 'status', 'automated_suite_status', 'external_channels_executed', 'real_device_executed', 'summary', 'report_hash', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (UatRun $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Completed UAT reports are append-only.'));
        static::deleting(fn () => throw new LogicException('Completed UAT reports are append-only.'));
    }

    protected function casts(): array
    {
        return ['external_channels_executed' => 'boolean', 'real_device_executed' => 'boolean', 'summary' => 'array', 'completed_at' => 'datetime'];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(UatCaseResult::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
