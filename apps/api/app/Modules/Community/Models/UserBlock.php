<?php

namespace App\Modules\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBlock extends Model
{
    protected $fillable = ['blocker_user_id', 'blocked_user_id'];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_user_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    public static function existsBetween(?User $viewer, ?int $targetId): bool
    {
        if ($viewer === null || $targetId === null || $viewer->id === $targetId) {
            return false;
        }

        return self::query()->where(function ($query) use ($viewer, $targetId): void {
            $query->where(fn ($q) => $q->where('blocker_user_id', $viewer->id)->where('blocked_user_id', $targetId))
                ->orWhere(fn ($q) => $q->where('blocker_user_id', $targetId)->where('blocked_user_id', $viewer->id));
        })->exists();
    }

    public static function excludeFromQuery(Builder $query, ?User $viewer, string $ownerColumn): Builder
    {
        if ($viewer === null) {
            return $query;
        }

        return $query->whereNotExists(function ($subquery) use ($viewer, $ownerColumn): void {
            $subquery->selectRaw('1')->from('user_blocks')->where(function ($query) use ($viewer, $ownerColumn): void {
                $query->where(fn ($q) => $q->where('user_blocks.blocker_user_id', $viewer->id)->whereColumn('user_blocks.blocked_user_id', $ownerColumn))
                    ->orWhere(fn ($q) => $q->where('user_blocks.blocked_user_id', $viewer->id)->whereColumn('user_blocks.blocker_user_id', $ownerColumn));
            });
        });
    }
}
