<?php

namespace App\Modules\Notification\Models;

use App\Modules\Auth\Models\UserIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WechatSubscriptionGrant extends Model
{
    protected $fillable = ['user_id', 'user_identity_id', 'provider_app_id_hash', 'provider_template_id', 'status', 'available_count', 'last_requested_at', 'last_accepted_at', 'last_consumed_at'];

    protected function casts(): array
    {
        return ['available_count' => 'integer', 'last_requested_at' => 'datetime', 'last_accepted_at' => 'datetime', 'last_consumed_at' => 'datetime'];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_identity_id');
    }
}
