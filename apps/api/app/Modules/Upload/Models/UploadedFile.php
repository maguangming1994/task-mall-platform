<?php

namespace App\Modules\Upload\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UploadedFile extends Model
{
    protected $fillable = [
        'public_id', 'owner_user_id', 'purpose', 'original_name', 'extension', 'declared_mime',
        'detected_mime', 'declared_size', 'actual_size', 'sha256', 'disk', 'quarantine_path',
        'status', 'rejection_code', 'upload_token_hash', 'upload_token_ciphertext', 'idempotency_key_hash',
        'ticket_expires_at', 'uploaded_at', 'scanned_at',
    ];

    protected $hidden = ['upload_token_hash', 'upload_token_ciphertext', 'idempotency_key_hash', 'quarantine_path', 'disk'];

    protected static function booted(): void
    {
        static::creating(function (UploadedFile $file): void {
            $file->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['ticket_expires_at' => 'datetime', 'uploaded_at' => 'datetime', 'scanned_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
