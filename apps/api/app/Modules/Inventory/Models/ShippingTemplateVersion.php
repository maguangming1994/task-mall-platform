<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingTemplateVersion extends Model
{
    protected $fillable = ['shipping_template_id', 'version_no', 'content', 'created_by_admin_id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'version_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Shipping template versions are append-only.'));
        static::deleting(fn () => throw new \LogicException('Shipping template versions are append-only.'));
    }
}
