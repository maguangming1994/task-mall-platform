<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class WechatProviderCredential extends Model
{
    protected $fillable = [
        'provider',
        'app_secret',
        'token',
        'encoding_aes_key',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = ['app_secret', 'token', 'encoding_aes_key'];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'token' => 'encrypted',
            'encoding_aes_key' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
