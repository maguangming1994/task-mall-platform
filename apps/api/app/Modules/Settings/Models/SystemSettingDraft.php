<?php

namespace App\Modules\Settings\Models;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSettingDraft extends Model
{
    protected $fillable = [
        'setting_definition_id', 'value', 'base_version_id', 'rollback_source_version_id',
        'reason', 'review_status', 'created_by_admin_id', 'updated_by_admin_id',
        'reviewed_by_admin_id', 'review_comment', 'reviewed_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return ['value' => 'json', 'reviewed_at' => 'datetime'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SystemSettingDefinition::class, 'setting_definition_id');
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(SystemSettingVersion::class, 'base_version_id');
    }

    public function rollbackSourceVersion(): BelongsTo
    {
        return $this->belongsTo(SystemSettingVersion::class, 'rollback_source_version_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_id');
    }
}
