<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreManagerSession extends Model
{
    protected $fillable = [
        'token_hash', 'store_id', 'user_id', 'last_active_at', 'expires_at',
        'revoked_at', 'ip_hash', 'user_agent_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
