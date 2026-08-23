<?php

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $fillable = ['public_id', 'user_id', 'assigned_admin_id', 'category', 'subject', 'status', 'priority', 'related_type', 'related_public_id', 'last_replied_at', 'closed_at'];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['last_replied_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }
}
