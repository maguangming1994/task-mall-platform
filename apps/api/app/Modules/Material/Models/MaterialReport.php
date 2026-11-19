<?php

namespace App\Modules\Material\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MaterialReport extends Model
{
    protected $fillable = [
        'public_id', 'material_id', 'user_id', 'reason', 'detail',
        'status', 'decision', 'handling_note', 'handled_by', 'handled_at',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
