<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    protected $fillable = ['public_id', 'parent_id', 'name', 'slug', 'status', 'sort_order'];

    protected $attributes = ['status' => 'active', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::creating(fn (ProductCategory $category) => $category->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
