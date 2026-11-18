<?php

namespace App\Modules\Material\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialUserQuota extends Model
{
    protected $fillable = ['user_id', 'daily_date', 'daily_used', 'bonus_quota'];

    protected $casts = [
        'daily_date' => 'date',
        'daily_used' => 'integer',
        'bonus_quota' => 'integer',
    ];
}
