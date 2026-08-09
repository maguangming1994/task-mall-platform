<?php

namespace App\Modules\Legal\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class LegalDocumentVersion extends Model
{
    protected $fillable = [
        'public_id',
        'document_type',
        'version',
        'title',
        'content_format',
        'content',
        'content_hash',
        'consent_required',
        'withdrawal_allowed',
        'status',
        'effective_at',
        'published_at',
        'change_summary',
        'created_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (LegalDocumentVersion $document): void {
            $document->public_id ??= (string) Str::ulid();
            $document->content_hash = hash('sha256', $document->content);
        });
        static::updating(function (LegalDocumentVersion $document): void {
            if ($document->getOriginal('status') === 'published') {
                throw new LogicException('Published legal documents are immutable.');
            }
            if ($document->isDirty('content')) {
                $document->content_hash = hash('sha256', $document->content);
            }
        });
        static::deleting(function (LegalDocumentVersion $document): void {
            if ($document->status === 'published') {
                throw new LogicException('Published legal documents cannot be deleted.');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'consent_required' => 'boolean',
            'withdrawal_allowed' => 'boolean',
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserLegalConsent::class);
    }
}
