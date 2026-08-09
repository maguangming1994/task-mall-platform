<?php

namespace App\Modules\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAssetAccount extends Model
{
    protected $fillable = ['user_id', 'points_available', 'points_frozen', 'points_debt', 'wallet_available_cents', 'wallet_frozen_cents', 'coupon_available', 'version'];

    protected function casts(): array
    {
        return [
            'points_available' => 'integer', 'points_frozen' => 'integer', 'points_debt' => 'integer',
            'wallet_available_cents' => 'integer', 'wallet_frozen_cents' => 'integer', 'coupon_available' => 'integer', 'version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
