<?php

namespace App\Modules\Legal\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class UserLegalConsent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'user_id',
        'legal_document_version_id',
        'action',
        'client_platform',
        'ip_hash',
        'user_agent_hash',
        'idempotency_key_hash',
        'request_id',
    ];

    protected $hidden = ['ip_hash', 'user_agent_hash', 'idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(function (UserLegalConsent $consent): void {
            $consent->public_id ??= (string) Str::ulid();
        });
        static::updating(fn () => throw new LogicException('User legal consents are append-only.'));
        static::deleting(fn () => throw new LogicException('User legal consents are append-only.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'legal_document_version_id');
    }
}
