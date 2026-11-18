<?php

namespace App\Modules\Push\Models;

use Illuminate\Database\Eloquent\Model;

class PushLevel extends Model
{
    protected $fillable = ['code', 'name', 'coefficient', 'team_bonus_rate', 'min_monthly_orders', 'sort_order', 'status'];

    protected function casts(): array
    {
        return [
            'coefficient' => 'decimal:3',
            'team_bonus_rate' => 'decimal:3',
            'min_monthly_orders' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
