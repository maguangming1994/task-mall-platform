<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskTemplate extends Model
{
    protected $fillable = [
        'public_id', 'code', 'name', 'description', 'category', 'status', 'current_version_no', 'lock_version',
        'copied_from_task_template_id', 'created_by_admin_id', 'updated_by_admin_id', 'idempotency_key_hash',
    ];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (TaskTemplate $template) => $template->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['current_version_no' => 'integer', 'lock_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaskTemplateVersion::class);
    }
}
