<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class AdminMfaRecoveryCode extends Model
{
    protected $fillable = ['admin_user_id', 'code_hash', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
