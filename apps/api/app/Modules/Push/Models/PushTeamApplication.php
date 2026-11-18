<?php

namespace App\Modules\Push\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PushTeamApplication extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id', 'campus_id', 'type', 'team_id', 'user_id', 'team_name', 'message', 'status',
        'reviewed_by_user_id', 'reviewed_by_admin_id', 'review_note', 'reviewed_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (PushTeamApplication $application) => $application->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(PushTeam::class, 'team_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }
}
