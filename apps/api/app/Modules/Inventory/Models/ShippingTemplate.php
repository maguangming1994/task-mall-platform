<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShippingTemplate extends Model
{
    protected $fillable = ['public_id', 'name', 'status', 'current_version_no', 'lock_version', 'idempotency_key_hash', 'created_by_admin_id', 'published_at'];

    protected $attributes = ['status' => 'draft', 'current_version_no' => 1, 'lock_version' => 1];

    protected $hidden = ['idempotency_key_hash'];

    protected static function booted(): void
    {
        static::creating(fn (ShippingTemplate $template) => $template->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'current_version_no' => 'integer', 'lock_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ShippingTemplateVersion::class);
    }
}
