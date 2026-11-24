<?php

namespace App\Modules\Robot\Models;

use Illuminate\Database\Eloquent\Model;

class RobotXpCredential extends Model
{
    protected $fillable = [
        'provider',
        'api_key',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
