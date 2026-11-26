<?php

namespace App\Modules\ExpressPickup\Printers;

use App\Modules\ExpressPickup\Models\CampusPrinter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * 飞鹅云打印开放接口。
 *
 * 文档要点：POST https://api.feieyun.cn/Api/Open/
 * 公共参数 user（账号）、stime（秒级时间戳）、sig = sha1(user + ukey + stime)；
 * 打印 action=Open_printMsg（sn、content、times），状态 action=Open_queryPrinterStatus（sn）。
 */
class FeiePrinterDriver implements PrinterDriver
{
    private const ENDPOINT = 'https://api.feieyun.cn/Api/Open/';

    private const MAX_CONTENT_LENGTH = 2000;

    public function vendor(): string
    {
        return CampusPrinter::VENDOR_FEIE;
    }

    public function print(CampusPrinter $printer, string $content): void
    {
        $result = $this->call($printer, [
            'action' => 'Open_printMsg',
            'sn' => $printer->sn,
            'content' => mb_substr($content, 0, self::MAX_CONTENT_LENGTH),
            'times' => '1',
        ]);
        if ((int) ($result['ret'] ?? -1) !== 0) {
            throw new RuntimeException('飞鹅打印失败：'.(string) ($result['msg'] ?? '未知错误'));
        }
    }

    public function test(CampusPrinter $printer): array
    {
        try {
            $result = $this->call($printer, ['action' => 'Open_queryPrinterStatus', 'sn' => $printer->sn]);
            $ok = (int) ($result['ret'] ?? -1) === 0;

            return [
                'ok' => $ok,
                'message' => (string) ($result['data'] ?? $result['msg'] ?? ($ok ? '连接成功' : '连接失败')),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function call(CampusPrinter $printer, array $params): array
    {
        $username = (string) ($printer->username ?? '');
        $ukey = $printer->secret();
        if ($username === '' || $ukey === null) {
            throw new RuntimeException('飞鹅打印机缺少账号或接口密钥。');
        }
        if ((string) $printer->sn === '') {
            throw new RuntimeException('飞鹅打印机缺少设备编号 SN。');
        }

        $stime = (string) time();
        $payload = array_merge([
            'user' => $username,
            'stime' => $stime,
            'sig' => sha1($username.$ukey.$stime),
        ], $params);

        $response = Http::asForm()->timeout(15)->post(self::ENDPOINT, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('飞鹅接口请求失败：HTTP '.$response->status());
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('飞鹅接口返回格式异常。');
        }

        return $json;
    }
}
