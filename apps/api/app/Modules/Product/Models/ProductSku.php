<?php

namespace App\Modules\Product\Models;

use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\ShippingTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ProductSku extends Model
{
    protected $fillable = ['public_id', 'product_id', 'sku_code', 'name', 'specs', 'image_file_public_id', 'fulfillment_type', 'shipping_template_id', 'weight_grams', 'free_shipping', 'low_stock_threshold', 'limit_per_order', 'limit_per_day', 'limit_campaign', 'limit_lifetime', 'status', 'sort_order', 'lock_version'];

    protected $attributes = ['status' => 'active', 'free_shipping' => false, 'sort_order' => 0, 'lock_version' => 1];

    protected static function booted(): void
    {
        static::creating(fn (ProductSku $sku) => $sku->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['specs' => 'array', 'free_shipping' => 'boolean', 'weight_grams' => 'integer', 'low_stock_threshold' => 'integer', 'limit_per_order' => 'integer', 'limit_per_day' => 'integer', 'limit_campaign' => 'integer', 'limit_lifetime' => 'integer', 'sort_order' => 'integer', 'lock_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOptions(): HasMany
    {
        return $this->hasMany(SkuPurchaseOption::class)->orderBy('id');
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class, 'product_sku_id');
    }

    public function shippingTemplate(): BelongsTo
    {
        return $this->belongsTo(ShippingTemplate::class);
    }
}
