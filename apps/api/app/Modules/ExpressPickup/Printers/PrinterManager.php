<?php

namespace App\Modules\ExpressPickup\Printers;

use App\Modules\ExpressPickup\Models\CampusPrinter;
use InvalidArgumentException;

class PrinterManager
{
    public function __construct(
        private readonly FeiePrinterDriver $feie,
        private readonly DaquPrinterDriver $daqu,
        private readonly ShangpengPrinterDriver $shangpeng,
    ) {}

    public function driver(string $vendor): PrinterDriver
    {
        return match ($vendor) {
            CampusPrinter::VENDOR_FEIE => $this->feie,
            CampusPrinter::VENDOR_DAQU => $this->daqu,
            CampusPrinter::VENDOR_SHANGPENG => $this->shangpeng,
            default => throw new InvalidArgumentException('未知的小票机厂商：'.$vendor),
        };
    }

    public function isReady(string $vendor): bool
    {
        return $vendor === CampusPrinter::VENDOR_FEIE;
    }
}
