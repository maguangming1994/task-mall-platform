<?php

namespace Tests\Unit;

use App\Modules\ExpressPickup\Models\CampusPrinter;
use App\Modules\ExpressPickup\Printers\FeiePrinterDriver;
use App\Modules\ExpressPickup\Printers\PrinterManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FeiePrinterDriverTest extends TestCase
{
    public function test_print_sends_signed_form_payload(): void
    {
        Http::fake(['https://api.feieyun.cn/Api/Open/' => Http::response(['ret' => 0, 'msg' => 'ok'])]);

        $printer = $this->printer();
        (new FeiePrinterDriver)->print($printer, '<C>快递代取</C>');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $expected = sha1('feie-user'.'feie-ukey-secret'.$data['stime']);

            return $request->url() === 'https://api.feieyun.cn/Api/Open/'
                && $data['action'] === 'Open_printMsg'
                && $data['sn'] === 'FEIE-SN-0001'
                && $data['user'] === 'feie-user'
                && $data['sig'] === $expected
                && $data['content'] === '<C>快递代取</C>';
        });
    }

    public function test_print_throws_when_vendor_returns_error(): void
    {
        Http::fake(['https://api.feieyun.cn/Api/Open/' => Http::response(['ret' => 1001, 'msg' => '设备离线'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('设备离线');
        (new FeiePrinterDriver)->print($this->printer(), '内容');
    }

    public function test_missing_credentials_fail_fast_without_http(): void
    {
        Http::fake();
        $printer = $this->printer();
        $printer->setSecret(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('飞鹅打印机缺少账号或接口密钥。');
        (new FeiePrinterDriver)->print($printer, '内容');
    }

    public function test_vendor_manager_marks_only_feie_ready(): void
    {
        $manager = app(PrinterManager::class);

        $this->assertTrue($manager->isReady('feie'));
        $this->assertFalse($manager->isReady('daqu'));
        $this->assertFalse($manager->isReady('shangpeng'));

        $result = $manager->driver('daqu')->test($this->printer());
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('大趋', $result['message']);
    }

    private function printer(): CampusPrinter
    {
        $printer = new CampusPrinter([
            'name' => '测试飞鹅',
            'vendor' => CampusPrinter::VENDOR_FEIE,
            'sn' => 'FEIE-SN-0001',
            'username' => 'feie-user',
            'paper_width' => 58,
        ]);
        $printer->setSecret('feie-ukey-secret');

        return $printer;
    }
}
