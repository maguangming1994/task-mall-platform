<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = ['code', 'name', 'group', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
