<?php

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureFlag extends Model
{
    protected $fillable = ['code', 'name', 'mode', 'rollout_percentage', 'audience', 'placeholder_title_reviewing', 'placeholder_desc_reviewing', 'placeholder_title_reviewed', 'placeholder_desc_reviewed', 'version', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['rollout_percentage' => 'integer', 'audience' => 'array', 'version' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FeatureFlagVersion::class);
    }
}
