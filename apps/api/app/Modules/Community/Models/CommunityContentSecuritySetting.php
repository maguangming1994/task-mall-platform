<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityContentSecuritySetting extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'enabled',
        'region',
        'endpoint',
        'service',
        'access_key_id',
        'access_key_secret',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = ['access_key_id', 'access_key_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'access_key_id' => 'encrypted',
            'access_key_secret' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
