<?php

namespace App\Modules\Major\Models;

use Illuminate\Database\Eloquent\Model;

class Major extends Model
{
    protected $fillable = [
        'code', 'name', 'category', 'category_sort', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category_sort' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
