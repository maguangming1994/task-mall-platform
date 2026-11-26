<?php

namespace App\Modules\ExpressPickup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PrinterPrintJob extends Model
{
    protected $fillable = [
        'public_id', 'campus_id', 'printer_id', 'business_type', 'business_id',
        'content', 'status', 'attempts', 'error_message', 'printed_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $job) => $job->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(CampusPrinter::class, 'printer_id');
    }
}
