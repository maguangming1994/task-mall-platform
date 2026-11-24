<?php

namespace App\Modules\Robot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RobotMessageRequest extends Model
{
    protected $fillable = [
        'public_id',
        'created_by_admin_id',
        'forward_config_id',
        'trigger_type',
        'idempotency_key_hash',
        'batch_id',
        'batch_sequence',
        'device_id',
        'resolved_device_id',
        'wxid',
        'group_id',
        'group_name',
        'message',
        'msg_type',
        'payload',
        'cmd_id',
        'status',
        'provider_status',
        'queued',
        'error_code',
        'error_message',
        'submitted_at',
        'last_synced_at',
        'scheduled_at',
        'dispatched_at',
        'canceled_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (RobotMessageRequest $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'queued' => 'boolean',
            'batch_sequence' => 'integer',
            'payload' => 'array',
            'submitted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function forwardConfig(): BelongsTo
    {
        return $this->belongsTo(RobotForwardConfig::class, 'forward_config_id')->withTrashed();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
