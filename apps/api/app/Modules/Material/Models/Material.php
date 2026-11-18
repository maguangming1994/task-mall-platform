<?php

namespace App\Modules\Material\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Material extends Model
{
    use BelongsToCampus;

    public const NETDISK_TYPES = [
        'baidu' => '百度网盘',
        'quark' => '夸克网盘',
        'aliyun' => '阿里云盘',
        'other' => '其他资源',
    ];

    protected $fillable = [
        'public_id', 'campus_id', 'category_id', 'uploader_user_id', 'uploader_name',
        'title', 'description', 'netdisk_type', 'share_url', 'extract_code', 'share_text',
        'file_type', 'source', 'status', 'reject_reason', 'reviewed_by', 'reviewed_at',
        'bonus_granted_at', 'view_count', 'fetch_count',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'bonus_granted_at' => 'datetime',
        'view_count' => 'integer',
        'fetch_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * 资料共享允许平台级资料（campus_id 为空）对所有校区可见；
     * 用户投喂的资料仍带 campus_id，strict 模式下按本校隔离。
     */
    public function applyCampusScope(Builder $builder, ?int $campusId): void
    {
        if ($campusId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(function (Builder $query) use ($campusId): void {
            $query->whereNull($query->qualifyColumn('campus_id'))
                ->orWhere($query->qualifyColumn('campus_id'), $campusId);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'category_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }
}
