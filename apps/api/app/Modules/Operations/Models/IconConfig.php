<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;

class IconConfig extends Model
{
    protected $fillable = [
        'code', 'name', 'category', 'svg_path', 'fallback_char',
        'label', 'description', 'tone', 'sort_order', 'is_active', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
