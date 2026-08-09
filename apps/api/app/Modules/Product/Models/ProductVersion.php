<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVersion extends Model
{
    protected $fillable = ['product_id', 'version_no', 'snapshot', 'changed_fields', 'created_by_admin_id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'changed_fields' => 'array', 'version_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Product versions are append-only.'));
        static::deleting(fn () => throw new \LogicException('Product versions are append-only.'));
    }
}
