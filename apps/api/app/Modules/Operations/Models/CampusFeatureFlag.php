<?php

namespace App\Modules\Operations\Models;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Campus\Models\Campus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 校区对单个功能模块的覆盖状态：inherit / enabled / disabled。
 */
class CampusFeatureFlag extends Model
{
    protected $fillable = ['campus_id', 'code', 'status', 'version', 'updated_by_admin_id'];

    protected $attributes = [
        'status' => 'inherit',
        'version' => 1,
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_id');
    }
}
