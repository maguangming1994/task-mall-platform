<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class IdentityProviderCredential extends Model
{
    protected $fillable = ['provider', 'access_key_id', 'access_key_secret', 'security_token'];

    protected $hidden = ['access_key_id', 'access_key_secret', 'security_token'];

    protected function casts(): array
    {
        return [
            'access_key_id' => 'encrypted',
            'access_key_secret' => 'encrypted',
            'security_token' => 'encrypted',
        ];
    }
}
