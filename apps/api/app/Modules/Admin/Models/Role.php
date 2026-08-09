<?php

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['code', 'name', 'description', 'system_role'];

    protected function casts(): array
    {
        return ['system_role' => 'boolean'];
    }

    public function adminUsers(): BelongsToMany
    {
        return $this->belongsToMany(AdminUser::class, 'admin_user_role');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }
}
