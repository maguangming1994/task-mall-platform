<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

class WechatVerificationFile extends Model
{
    protected $fillable = ['filename', 'content', 'uploaded_by_admin_id'];
}
