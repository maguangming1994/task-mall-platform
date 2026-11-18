<?php

namespace App\Modules\Material\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MaterialCategory extends Model
{
    protected $fillable = ['public_id', 'name', 'icon', 'sort_order', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class, 'category_id');
    }
}
