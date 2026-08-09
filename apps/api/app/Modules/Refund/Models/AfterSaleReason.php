<?php

namespace App\Modules\Refund\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AfterSaleReason extends Model
{
    protected $fillable = ['public_id', 'code', 'name', 'service_types', 'default_responsibility', 'return_shipping_payer', 'evidence_required', 'enabled', 'sort_order', 'version'];

    protected static function booted(): void
    {
        static::creating(fn (AfterSaleReason $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['service_types' => 'array', 'evidence_required' => 'boolean', 'enabled' => 'boolean', 'sort_order' => 'integer', 'version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
