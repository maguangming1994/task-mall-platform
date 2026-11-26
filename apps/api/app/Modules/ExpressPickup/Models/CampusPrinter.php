<?php

namespace App\Modules\ExpressPickup\Models;

use App\Modules\Campus\Models\Concerns\BelongsToCampus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CampusPrinter extends Model
{
    use BelongsToCampus;

    public const VENDOR_FEIE = 'feie';
    public const VENDOR_DAQU = 'daqu';
    public const VENDOR_SHANGPENG = 'shangpeng';

    public const VENDORS = [
        self::VENDOR_FEIE => '飞鹅',
        self::VENDOR_DAQU => '大趋',
        self::VENDOR_SHANGPENG => '商鹏',
    ];

    protected $fillable = [
        'public_id', 'campus_id', 'name', 'vendor', 'sn', 'username', 'secret_encrypted',
        'paper_width', 'template', 'status', 'last_test_at', 'last_test_status',
        'last_test_message', 'created_by_admin_id', 'updated_by_admin_id',
    ];

    protected $hidden = ['secret_encrypted'];

    protected static function booted(): void
    {
        static::creating(fn (self $printer) => $printer->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'paper_width' => 'integer',
            'last_test_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function secret(): ?string
    {
        if ($this->secret_encrypted === null || $this->secret_encrypted === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($this->secret_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setSecret(?string $plain): void
    {
        $this->secret_encrypted = ($plain === null || $plain === '')
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString($plain);
    }
}
