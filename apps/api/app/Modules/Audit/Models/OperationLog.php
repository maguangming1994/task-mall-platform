<?php

namespace App\Modules\Audit\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class OperationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'admin_user_id',
        'action',
        'subject_type',
        'subject_id',
        'before_value',
        'after_value',
        'reason',
        'ip_hash',
        'request_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (OperationLog $log): void {
            $log->public_id ??= (string) Str::ulid();
        });
        static::updating(fn () => throw new LogicException('Operation logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Operation logs are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'before_value' => 'array',
            'after_value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
