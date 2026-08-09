<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SystemSettingDefinition extends Model
{
    protected $fillable = [
        'setting_key', 'group_code', 'name', 'description', 'value_type', 'unit',
        'options', 'validation_rules', 'default_value', 'risk_level', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'json',
            'validation_rules' => 'json',
            'default_value' => 'json',
            'enabled' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SystemSettingVersion::class, 'setting_definition_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(SystemSettingDraft::class, 'setting_definition_id');
    }
}
