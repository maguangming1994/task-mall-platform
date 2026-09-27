<?php

namespace App\Modules\Task\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskPublisher extends Model
{
    protected $fillable = ['public_id', 'publisher_type', 'admin_user_id', 'linked_user_id', 'verification_status', 'publisher_level', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (TaskPublisher $model) => $model->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
