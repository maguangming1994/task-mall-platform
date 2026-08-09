<?php

namespace App\Modules\Privacy\Models;

use App\Models\User;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PrivacyRequest extends Model
{
    protected $fillable = ['public_id', 'user_id', 'request_type', 'scopes', 'description', 'status', 'blockers', 'result_file_id', 'result_expires_at', 'idempotency_key_hash', 'resolution_note', 'resolved_by_admin_id', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (PrivacyRequest $request) => $request->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['scopes' => 'array', 'blockers' => 'array', 'result_expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resultFile(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class, 'result_file_id');
    }
}
