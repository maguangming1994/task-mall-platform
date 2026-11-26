<?php

namespace App\Modules\ExpressPickup\Printers;

class ShangpengPrinterDriver extends PendingVendorPrinterDriver
{
    public function vendor(): string
    {
        return 'shangpeng';
    }

    protected function vendorLabel(): string
    {
        return '商鹏';
    }
}
