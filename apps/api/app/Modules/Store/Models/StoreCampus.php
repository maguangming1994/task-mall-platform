<?php

namespace App\Modules\Store\Models;

use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCampus extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = ['store_id', 'campus_id', 'status', 'created_by_admin_id'];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
