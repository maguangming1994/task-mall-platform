<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WalletRecharge extends Model
{
    protected $fillable = ['public_id', 'recharge_no', 'user_id', 'method', 'amount_cents', 'status', 'payer_name', 'offline_reference', 'note', 'offline_proof_file_ids', 'reviewed_by_admin_user_id', 'review_note', 'idempotency_key_hash', 'request_fingerprint_hash', 'expires_at', 'reviewed_at', 'succeeded_at'];

    protected $hidden = ['idempotency_key_hash', 'request_fingerprint_hash'];

    protected static function booted(): void
    {
        static::creating(fn (WalletRecharge $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'offline_proof_file_ids' => 'array', 'expires_at' => 'datetime', 'reviewed_at' => 'datetime', 'succeeded_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_user_id'); }
    public function payments(): HasMany { return $this->hasMany(\App\Modules\Payment\Models\Payment::class); }
}
