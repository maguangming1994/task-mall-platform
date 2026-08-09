<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NotificationCampaign extends Model
{
    protected $fillable = ['public_id', 'name', 'title', 'body', 'channels', 'status', 'recipient_count', 'sent_count', 'idempotency_key_hash', 'created_by_admin_id', 'scheduled_at', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (NotificationCampaign $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['channels' => 'array', 'recipient_count' => 'integer', 'sent_count' => 'integer', 'scheduled_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
