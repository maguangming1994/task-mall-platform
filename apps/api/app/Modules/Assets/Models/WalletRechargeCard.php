<?php

namespace App\Modules\Assets\Models;

use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletRechargeCard extends Model
{
    protected $fillable = ['public_id', 'code_hash', 'code_mask', 'amount_cents', 'status', 'expires_at', 'redeemed_by_user_id', 'redeemed_recharge_id', 'redeemed_at', 'created_by_admin_user_id'];
    protected $hidden = ['code_hash'];

    protected static function booted(): void
    {
        static::creating(fn (WalletRechargeCard $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'expires_at' => 'datetime', 'redeemed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function redeemedBy(): BelongsTo { return $this->belongsTo(User::class, 'redeemed_by_user_id'); }
    public function recharge(): BelongsTo { return $this->belongsTo(WalletRecharge::class, 'redeemed_recharge_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(AdminUser::class, 'created_by_admin_user_id'); }
}
