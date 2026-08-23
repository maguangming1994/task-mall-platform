<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportTicketMessage extends Model
{
    protected $fillable = ['public_id', 'support_ticket_id', 'sender_type', 'sender_id', 'content', 'attachment_file_ids'];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['attachment_file_ids' => 'array'];
    }
}
