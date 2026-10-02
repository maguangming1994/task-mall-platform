<?php

namespace App\Modules\SecondHand\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SecondHandCategory extends Model
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

    public function items(): HasMany
    {
        return $this->hasMany(SecondHandItem::class, 'category_id');
    }
}
