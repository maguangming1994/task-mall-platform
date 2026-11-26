<?php

namespace App\Modules\ExpressPickup\Printers;

use App\Modules\ExpressPickup\Models\CampusPrinter;
use RuntimeException;

/**
 * 大趋 / 商鹏 云接口文档尚未提供时的占位驱动。
 * 拿到文档后，新建对应 Driver 实现 PrinterDriver 并在 PrinterManager 注册即可。
 */
abstract class PendingVendorPrinterDriver implements PrinterDriver
{
    public function print(CampusPrinter $printer, string $content): void
    {
        throw new RuntimeException($this->vendorLabel().'小票机接口尚未接入，请先提供接口文档与测试账号。');
    }

    public function test(CampusPrinter $printer): array
    {
        return ['ok' => false, 'message' => $this->vendorLabel().'小票机接口尚未接入。'];
    }

    abstract protected function vendorLabel(): string;
}
