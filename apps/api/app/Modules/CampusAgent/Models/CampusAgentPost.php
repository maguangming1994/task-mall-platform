<?php

namespace App\Modules\CampusAgent\Models;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CampusAgentPost extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        'notice' => '公告',
        'experience' => '经验分享',
        'question' => '求助',
    ];

    protected $fillable = [
        'public_id',
        'author_admin_id',
        'author_campus_id',
        'title',
        'content',
        'category_code',
        'status',
        'view_count',
        'like_count',
        'comment_count',
        'published_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CampusAgentPost $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'author_admin_id' => 'integer',
            'author_campus_id' => 'integer',
            'view_count' => 'integer',
            'like_count' => 'integer',
            'comment_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_admin_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'author_campus_id');
    }
}
