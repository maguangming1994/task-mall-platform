<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DeploymentRelease extends Model
{
    protected $fillable = ['public_id', 'release_version', 'environment', 'commit_sha', 'artifact_hash', 'configuration_hash', 'status', 'rollout_percentage', 'created_by_admin_id', 'deployed_at', 'rolled_back_at'];

    protected static function booted(): void
    {
        static::creating(fn (DeploymentRelease $release) => $release->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['rollout_percentage' => 'integer', 'deployed_at' => 'datetime', 'rolled_back_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeploymentEvent::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
