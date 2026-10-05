<?php

namespace App\Modules\SecondHand\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SecondHandItem extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'seller_user_id', 'category_id',
        'title', 'description', 'price', 'condition', 'cover_file_public_id',
        'images', 'trade_type', 'location', 'status', 'audit_status', 'rejected_reason',
        'reviewed_by', 'reviewed_at',
        'view_count', 'favorite_count',
        'idempotency_key_hash',
    ];

    protected $casts = [
        'images' => 'array',
        'price' => 'integer',
        'view_count' => 'integer',
        'favorite_count' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SecondHandCategory::class, 'category_id');
    }

    /** 最近一次执行审核的管理员 */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SecondHandOrder::class, 'item_id');
    }
}
