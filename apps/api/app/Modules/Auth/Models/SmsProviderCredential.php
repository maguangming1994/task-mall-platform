<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class SmsProviderCredential extends Model
{
    protected $fillable = [
        'provider',
        'access_key_id',
        'access_key_secret',
        'security_token',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = ['access_key_id', 'access_key_secret', 'security_token'];

    protected function casts(): array
    {
        return [
            'access_key_id' => 'encrypted',
            'access_key_secret' => 'encrypted',
            'security_token' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
