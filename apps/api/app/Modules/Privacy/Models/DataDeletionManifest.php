<?php

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

class DataDeletionManifest extends Model
{
    protected $fillable = ['user_id', 'user_public_id_hash', 'deletion_version', 'scopes', 'effective_at', 'last_replayed_at'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'effective_at' => 'datetime', 'last_replayed_at' => 'datetime'];
    }
}
