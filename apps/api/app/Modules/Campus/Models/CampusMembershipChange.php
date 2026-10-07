<?php

namespace App\Modules\Campus\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampusMembershipChange extends Model
{
    protected $table = 'campus_membership_changes';

    protected $fillable = [
        'user_id',
        'from_campus_id',
        'to_campus_id',
        'reason',
        'changed_by_admin_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromCampus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'from_campus_id');
    }

    public function toCampus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'to_campus_id');
    }
}
