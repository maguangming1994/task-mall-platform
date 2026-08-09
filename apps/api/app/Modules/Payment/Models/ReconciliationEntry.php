<?php

namespace App\Modules\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ReconciliationEntry extends Model
{
    protected $fillable = ['public_id', 'reconciliation_run_id', 'payment_id', 'payment_no', 'provider_trade_no', 'provider_amount', 'local_amount', 'provider_state', 'local_state', 'result', 'resolution_status', 'source_row_hash'];

    protected static function booted(): void
    {
        static::creating(fn (ReconciliationEntry $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['provider_amount' => 'integer', 'local_amount' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
