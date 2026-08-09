<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DeploymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['deployment_release_id', 'event_type', 'from_status', 'to_status', 'rollout_percentage', 'reason', 'evidence_hash', 'admin_user_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Deployment events are append-only.'));
        static::deleting(fn () => throw new LogicException('Deployment events are append-only.'));
    }
}
