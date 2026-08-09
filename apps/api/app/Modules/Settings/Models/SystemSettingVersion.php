<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SystemSettingVersion extends Model
{
    protected $fillable = [
        'setting_definition_id', 'version', 'value', 'source', 'source_version_id',
        'reason', 'published_by_admin_id', 'approved_by_admin_id', 'published_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published system setting versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Published system setting versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['value' => 'json', 'published_at' => 'datetime'];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SystemSettingDefinition::class, 'setting_definition_id');
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_version_id');
    }
}
