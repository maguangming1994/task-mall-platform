<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StoreProduct extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ON_SALE = 'on_sale';
    public const STATUS_OFF_SHELF = 'off_shelf';

    protected $fillable = [
        'public_id', 'store_id', 'store_category_id', 'campus_id', 'name', 'subtitle',
        'description', 'cover_url', 'gallery_images', 'status', 'admin_off_shelf_reason',
        'published_at', 'off_shelf_at', 'lock_version', 'created_by_user_id',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'lock_version' => 1];

    protected static function booted(): void
    {
        static::creating(fn (self $product) => $product->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'gallery_images' => 'array',
            'lock_version' => 'integer',
            'published_at' => 'datetime',
            'off_shelf_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'store_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function skus(): HasMany
    {
        return $this->hasMany(StoreProductSku::class)->orderBy('sort_order')->orderBy('id');
    }
}
