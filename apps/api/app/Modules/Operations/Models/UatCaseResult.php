<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UatCaseResult extends Model
{
    protected $fillable = ['uat_run_id', 'case_code', 'scope', 'status', 'evidence'];

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(UatRun::class, 'uat_run_id');
    }
}
