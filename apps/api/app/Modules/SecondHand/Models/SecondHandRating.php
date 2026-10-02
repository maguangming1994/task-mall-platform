<?php

namespace App\Modules\SecondHand\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SecondHandRating extends Model
{
    protected $fillable = [
        'public_id', 'order_id', 'item_id', 'rater_user_id', 'ratee_user_id',
        'score', 'content', 'images', 'reply_content', 'replied_at',
    ];

    protected $casts = ['images' => 'array', 'replied_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SecondHandOrder::class, 'order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SecondHandItem::class, 'item_id');
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_user_id');
    }

    public function ratee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ratee_user_id');
    }
}
