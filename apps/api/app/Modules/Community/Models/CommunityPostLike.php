<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;

class CommunityPostLike extends Model
{
    protected $fillable = ['community_post_id', 'user_id'];
}
