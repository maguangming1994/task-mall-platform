<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StoreCategory extends Model
{
    protected $fillable = ['public_id', 'store_id', 'name', 'sort_order', 'status'];

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::creating(fn (self $category) => $category->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }
}
