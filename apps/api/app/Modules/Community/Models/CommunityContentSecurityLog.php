<?php

namespace App\Modules\Community\Models;

use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommunityContentSecurityLog extends Model
{
    use BelongsToCampus;

    protected $fillable = [
        'public_id',
        'campus_id',
        'user_id',
        'content_type',
        'content_id',
        'content_public_id',
        'content_version_no',
        'content_field',
        'subject_snapshot',
        'content_snapshot',
        'content_hash',
        'provider',
        'verdict',
        'risk_level',
        'risk_labels',
        'skip_reason',
        'request_id',
        'response_time_ms',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CommunityContentSecurityLog $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'risk_labels' => 'array',
            'content_id' => 'integer',
            'content_version_no' => 'integer',
            'response_time_ms' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
