<?php

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

class LegalHold extends Model
{
    protected $fillable = ['object_type', 'object_id', 'reason', 'approved_by_admin_id', 'starts_at', 'ends_at', 'released_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
