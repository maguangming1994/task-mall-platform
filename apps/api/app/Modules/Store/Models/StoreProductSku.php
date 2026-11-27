<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StoreProductSku extends Model
{
    protected $fillable = [
        'public_id', 'store_product_id', 'sku_code', 'name', 'specs', 'image_url',
        'fulfillment_type', 'weight_grams', 'cash_amount', 'limit_per_order',
        'status', 'sort_order', 'lock_version',
    ];

    protected $attributes = ['status' => 'active', 'sort_order' => 0, 'lock_version' => 1, 'fulfillment_type' => 'logistics', 'cash_amount' => 0];

    protected static function booted(): void
    {
        static::creating(fn (self $sku) => $sku->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'weight_grams' => 'integer',
            'cash_amount' => 'integer',
            'limit_per_order' => 'integer',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'store_product_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(StoreInventory::class, 'store_product_sku_id');
    }

    /** 指定校区的库存行；已预载 inventories 时优先复用集合，避免重复查询。 */
    public function inventoryForCampus(?int $campusId): ?StoreInventory
    {
        if ($this->relationLoaded('inventories')) {
            return $this->inventories->first(fn (StoreInventory $inventory): bool => (int) $inventory->campus_id === (int) $campusId);
        }

        return $this->inventories()->where('campus_id', $campusId)->first();
    }
}
