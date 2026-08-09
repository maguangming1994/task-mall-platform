<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = ['public_id', 'product_category_id', 'name', 'subtitle', 'description', 'cover_file_public_id', 'gallery_images', 'status', 'current_version_no', 'lock_version', 'idempotency_key_hash', 'created_by_admin_id', 'published_at', 'off_shelf_at'];

    protected $attributes = ['status' => 'draft', 'current_version_no' => 1, 'lock_version' => 1];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (Product $product) => $product->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['gallery_images' => 'array', 'published_at' => 'datetime', 'off_shelf_at' => 'datetime', 'current_version_no' => 'integer', 'lock_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function skus(): HasMany
    {
        return $this->hasMany(ProductSku::class)->orderBy('sort_order')->orderBy('id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class);
    }
}
