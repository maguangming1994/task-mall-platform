<?php

namespace App\Modules\ExpressPickup\Printers;

class DaquPrinterDriver extends PendingVendorPrinterDriver
{
    public function vendor(): string
    {
        return 'daqu';
    }

    protected function vendorLabel(): string
    {
        return '大趋';
    }
}
