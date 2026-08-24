<?php

namespace App\Modules\Payment\Models;

use Illuminate\Database\Eloquent\Model;

class WechatPaymentCredential extends Model
{
    protected $fillable = [
        'provider', 'api_v3_key', 'merchant_private_key', 'platform_certificate',
        'last_tested_at', 'last_test_status', 'last_test_message',
    ];

    protected $hidden = ['api_v3_key', 'merchant_private_key', 'platform_certificate'];

    protected function casts(): array
    {
        return [
            'api_v3_key' => 'encrypted',
            'merchant_private_key' => 'encrypted',
            'platform_certificate' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }
}
