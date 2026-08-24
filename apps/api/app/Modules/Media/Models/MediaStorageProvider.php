<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;

class MediaStorageProvider extends Model
{
    protected $fillable = ['provider', 'display_name', 'enabled', 'active', 'endpoint', 'region', 'bucket', 'private_bucket', 'public_base_url', 'access_key', 'secret_key', 'last_tested_at', 'last_test_status', 'last_test_message'];

    protected $hidden = ['access_key', 'secret_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'active' => 'boolean', 'access_key' => 'encrypted', 'secret_key' => 'encrypted', 'last_tested_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'provider';
    }
}
