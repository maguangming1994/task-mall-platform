<?php

namespace App\Modules\Major\Models;

use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampusMajor extends Model
{
    protected $fillable = [
        'campus_id', 'major_name', 'major_code', 'category', 'level', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
