<?php

namespace App\Modules\Robot\Models;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class RobotPostBot extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'name', 'enabled', 'campus_id', 'category_code', 'content_source',
        'author_user_id', 'posts_per_day', 'posts_per_run', 'active_weekdays', 'active_start', 'active_end',
        'min_gap_minutes', 'history_min_likes', 'ai_prompt', 'last_posted_at', 'posted_date',
        'posted_today', 'last_error', 'created_by_admin_id', 'updated_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (RobotPostBot $bot) => $bot->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'active_weekdays' => 'array',
            'posts_per_day' => 'integer',
            'posts_per_run' => 'integer',
            'min_gap_minutes' => 'integer',
            'history_min_likes' => 'integer',
            'posted_today' => 'integer',
            'last_posted_at' => 'datetime',
            'posted_date' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(RobotPostTemplate::class);
    }
}
