<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TaskReviewReason extends Model
{
    protected $fillable = ['public_id', 'code', 'label', 'suggested_note', 'sort_order', 'status', 'system_defined'];

    protected static function booted(): void
    {
        static::creating(fn (TaskReviewReason $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'system_defined' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
