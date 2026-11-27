<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Store extends Model
{
    use BelongsToCampus;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'public_id', 'owner_user_id', 'campus_id', 'name', 'logo_url', 'description',
        'contact_phone', 'address', 'business_hours', 'status', 'reject_reason',
        'commission_rate_bps', 'shipping_fee_amount', 'free_shipping_threshold',
        'applied_at', 'reviewed_at', 'reviewed_by_admin_id', 'lock_version',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING, 'commission_rate_bps' => 500, 'lock_version' => 1];

    protected static function booted(): void
    {
        static::creating(fn (self $store) => $store->public_id ??= (string) Str::ulid());
        // 主校区默认开通，后续由平台在后台追加/停用其他校区
        static::created(function (self $store): void {
            if ($store->campus_id !== null) {
                StoreCampus::query()->firstOrCreate(['store_id' => $store->id, 'campus_id' => $store->campus_id]);
            }
        });
    }

    /**
     * 门店校区可见性：消费者浏览与后台校区视角按「开通校区」过滤；
     * 店主「我的门店」与门店工作台按成员归属读取，不做校区限制。
     */
    public static function bootBelongsToCampus(): void
    {
        static::addGlobalScope('campus_context', function (Builder $builder): void {
            if (! app()->bound('request')) {
                return;
            }
            $request = request();
            if ($request->is('api/v1/me/stores*', 'api/v1/store-manager*')) {
                return;
            }
            $campusId = $request->attributes->get('campus_database_id');
            if (! is_int($campusId)) {
                if ($request->attributes->get('campus_strict', false)) {
                    $builder->whereRaw('1 = 0');
                }

                return;
            }
            $builder->whereHas('campuses', fn (Builder $query) => $query->where('campus_id', $campusId)->where('status', StoreCampus::STATUS_ACTIVE));
        });

        static::creating(function ($model): void {
            if ($model->campus_id !== null || ! app()->bound('request')) {
                return;
            }
            $campusId = request()->attributes->get('campus_database_id');
            if (is_int($campusId)) {
                $model->campus_id = $campusId;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'commission_rate_bps' => 'integer',
            'shipping_fee_amount' => 'integer',
            'free_shipping_threshold' => 'integer',
            'lock_version' => 'integer',
            'applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(StoreCategory::class);
    }

    public function campuses(): HasMany
    {
        return $this->hasMany(StoreCampus::class);
    }

    public function activeCampuses(): HasMany
    {
        return $this->hasMany(StoreCampus::class)->where('status', StoreCampus::STATUS_ACTIVE);
    }

    public function products(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(StoreSettlement::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
