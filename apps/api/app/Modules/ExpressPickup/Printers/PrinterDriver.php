<?php

namespace App\Modules\ExpressPickup\Printers;

use App\Modules\ExpressPickup\Models\CampusPrinter;

interface PrinterDriver
{
    public function vendor(): string;

    public function print(CampusPrinter $printer, string $content): void;

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(CampusPrinter $printer): array;
}
