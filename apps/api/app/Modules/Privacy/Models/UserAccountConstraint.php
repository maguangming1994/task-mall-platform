<?php

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

class UserAccountConstraint extends Model
{
    protected $fillable = ['user_id', 'blocker_code', 'source_type', 'source_id', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
