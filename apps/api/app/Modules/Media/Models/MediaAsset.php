<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MediaAsset extends Model
{
    protected $fillable = ['public_id', 'source_type', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'storage_provider', 'object_path', 'public_url', 'status', 'metadata', 'created_by_admin_id', 'created_by_user_id'];

    protected static function booted(): void
    {
        static::creating(fn (MediaAsset $asset) => $asset->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function deliveryUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/media/assets/'.$this->public_id;
    }
}
