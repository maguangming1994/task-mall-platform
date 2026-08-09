<?php

namespace App\Modules\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ReconciliationRun extends Model
{
    protected $fillable = ['public_id', 'provider', 'business_date', 'source', 'status', 'idempotency_key_hash', 'provider_count', 'provider_amount', 'local_count', 'local_amount', 'matched_count', 'difference_count', 'created_by_admin_id', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (ReconciliationRun $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['business_date' => 'date', 'provider_count' => 'integer', 'provider_amount' => 'integer', 'local_count' => 'integer', 'local_amount' => 'integer', 'matched_count' => 'integer', 'difference_count' => 'integer', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ReconciliationEntry::class);
    }
}
