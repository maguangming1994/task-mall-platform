<?php

namespace App\Modules\Chat\Models;

use App\Models\User;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChatConversation extends Model
{
    protected $fillable = [
        'public_id', 'user_a_id', 'user_b_id', 'item_id', 'order_id',
        'last_message', 'last_message_type', 'last_message_id', 'last_message_at',
        'unread_a', 'unread_b',
    ];

    protected $casts = ['last_message_at' => 'datetime', 'unread_a' => 'integer', 'unread_b' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SecondHandItem::class, 'item_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SecondHandOrder::class, 'order_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('id');
    }
}
