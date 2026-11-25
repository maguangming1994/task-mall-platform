<?php

namespace App\Modules\Robot\Models;

use App\Modules\Community\Models\CommunityPost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RobotPostForwardLog extends Model
{
    protected $fillable = [
        'public_id',
        'post_id',
        'content_type',
        'forward_config_id',
        'group_id',
        'group_name',
        'status',
        'message_request_id',
        'error_message',
        'manual',
        'forwarded_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (RobotPostForwardLog $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'manual' => 'boolean',
            'forwarded_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id')->withTrashed();
    }

    public function forwardConfig(): BelongsTo
    {
        return $this->belongsTo(RobotForwardConfig::class, 'forward_config_id')->withTrashed();
    }

    public function messageRequest(): BelongsTo
    {
        return $this->belongsTo(RobotMessageRequest::class, 'message_request_id');
    }
}
