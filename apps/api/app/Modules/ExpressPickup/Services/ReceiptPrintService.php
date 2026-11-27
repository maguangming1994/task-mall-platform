<?php

namespace App\Modules\ExpressPickup\Services;

use App\Modules\Campus\Models\Campus;
use App\Modules\ExpressPickup\Models\CampusPrinter;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\PrinterPrintJob;
use App\Modules\ExpressPickup\Printers\PrinterManager;
use App\Modules\ExpressPickup\Support\ReceiptTemplateRenderer;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReceiptPrintService
{
    private const BUSINESS_TYPE = 'express_pickup_order';

    public function __construct(
        private readonly PrinterManager $printers,
        private readonly ReceiptTemplateRenderer $renderer,
        private readonly ReceiptTemplateService $templates,
    ) {}

    public function defaultTemplate(): string
    {
        return implode("\n", [
            '<C><B>快递代取</B></C>',
            '${divider}',
            '取件点：${pickup_site}',
            '取件码：${pickup_code}',
            '包裹：${package_info} × ${package_count}',
            '送达：${deliver_building} ${deliver_address}',
            '联系：${contact_name} ${contact_phone}',
            '跑腿费：￥${reward}',
            '期望送达：${expected_at}',
            '${divider}',
            '订单号：${order_no}',
            '下单时间：${created_at}',
            '${divider}',
            '<C><QR>${order_no}</QR></C>',
        ]);
    }

    /**
     * 支付成功后的自动打印：失败只记录，不影响订单与支付。
     */
    public function printOrder(ExpressPickupOrder $order): void
    {
        if ($order->status === ExpressPickupOrder::STATUS_CANCELLED) {
            return;
        }
        try {
            $printer = $this->activePrinter((int) $order->campus_id);
            if ($printer === null) {
                return;
            }
            $this->dispatch($printer, $order, false);
        } catch (Throwable $exception) {
            Log::warning('快递代取小票打印失败', [
                'order' => $order->public_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 定时重试失败的小票任务：只重试已支付订单，每单最多重试 3 次。
     */
    public function retryFailed(int $limit = 50): int
    {
        $count = 0;
        PrinterPrintJob::query()
            ->where('business_type', self::BUSINESS_TYPE)
            ->where('status', 'failed')
            ->where('attempts', '<', 3)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PrinterPrintJob $job) use (&$count): void {
                try {
                    if ($this->retry($job)) {
                        $count++;
                    }
                } catch (Throwable $exception) {
                    Log::warning('快递代取小票重试失败', [
                        'job' => $job->public_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        return $count;
    }

    /**
     * 后台手动补打：失败需要向上抛错，便于操作者看到原因。
     */
    public function reprint(ExpressPickupOrder $order): PrinterPrintJob
    {
        $printer = $this->activePrinter((int) $order->campus_id);
        if ($printer === null) {
            throw new \RuntimeException('该校区还没有启用中的小票机，请先在「小票机」中配置。');
        }

        return $this->dispatch($printer, $order, true);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testPrinter(CampusPrinter $printer): array
    {
        $result = $this->printers->driver($printer->vendor)->test($printer);
        $printer->forceFill([
            'last_test_at' => now(),
            'last_test_status' => $result['ok'] ? 'success' : 'failed',
            'last_test_message' => mb_substr($result['message'], 0, 290),
        ])->save();

        return $result;
    }

    /**
     * @param  array<string, string>|null  $variables
     */
    public function preview(CampusPrinter $printer, ?array $variables = null): string
    {
        return $this->renderer->render(
            $printer->template ?: $this->defaultTemplate(),
            $variables ?? $this->sampleVariables((int) $printer->campus_id),
            $printer->paper_width,
        );
    }

    public function renderOrder(CampusPrinter $printer, ExpressPickupOrder $order): string
    {
        $variables = $this->orderVariables($order);
        // 装修中心绑定并启用的快递代取模板优先，未配置时保持一机一模板的旧行为。
        $compiled = $this->templates->compiledForScene('express_pickup', $variables, (int) $order->campus_id);
        if ($compiled !== null) {
            return $compiled;
        }

        return $this->renderer->render(
            $printer->template ?: $this->defaultTemplate(),
            $variables,
            $printer->paper_width,
        );
    }

    private function retry(PrinterPrintJob $job): bool
    {
        $order = ExpressPickupOrder::query()->where('public_id', $job->business_id)->first();
        if ($order === null || $order->status === ExpressPickupOrder::STATUS_CANCELLED) {
            return false;
        }

        $printer = $job->printer_id !== null ? CampusPrinter::query()->find($job->printer_id) : null;
        if ($printer === null || $printer->status !== 'enabled') {
            $job->forceFill([
                'attempts' => $job->attempts + 1,
                'error_message' => '小票机不存在或已停用，暂不能重试。',
            ])->save();

            return false;
        }

        try {
            // 复用支付时的渲染内容，避免模板变更导致重打内容与原始小票不一致。
            $this->printers->driver($printer->vendor)->print($printer, $job->content);
            $job->forceFill([
                'status' => 'printed',
                'attempts' => $job->attempts + 1,
                'error_message' => null,
                'printed_at' => now(),
            ])->save();
            $order->forceFill([
                'print_status' => ExpressPickupOrder::PRINT_STATUS_PRINTED,
                'printed_at' => now(),
            ])->save();

            return true;
        } catch (Throwable $exception) {
            $job->forceFill([
                'attempts' => $job->attempts + 1,
                'error_message' => mb_substr($exception->getMessage(), 0, 480),
            ])->save();
            $order->forceFill(['print_status' => ExpressPickupOrder::PRINT_STATUS_FAILED])->save();

            return false;
        }
    }

    private function dispatch(CampusPrinter $printer, ExpressPickupOrder $order, bool $force): PrinterPrintJob
    {
        $content = $this->renderOrder($printer, $order);
        $job = PrinterPrintJob::query()->create([
            'campus_id' => $order->campus_id,
            'printer_id' => $printer->id,
            'business_type' => self::BUSINESS_TYPE,
            'business_id' => $order->public_id,
            'content' => $content,
            'status' => 'pending',
        ]);

        try {
            $this->printers->driver($printer->vendor)->print($printer, $content);
            $job->forceFill(['status' => 'printed', 'attempts' => 1, 'printed_at' => now()])->save();
            $order->forceFill(['print_status' => ExpressPickupOrder::PRINT_STATUS_PRINTED, 'printed_at' => now()])->save();
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => 'failed',
                'attempts' => 1,
                'error_message' => mb_substr($exception->getMessage(), 0, 480),
            ])->save();
            $order->forceFill(['print_status' => ExpressPickupOrder::PRINT_STATUS_FAILED])->save();
            if ($force) {
                throw $exception;
            }
        }

        return $job->fresh();
    }

    private function activePrinter(int $campusId): ?CampusPrinter
    {
        return CampusPrinter::query()
            ->where('campus_id', $campusId)
            ->where('status', 'enabled')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function orderVariables(ExpressPickupOrder $order): array
    {
        return [
            'order_no' => (string) $order->order_no,
            'pickup_site' => (string) $order->pickup_site,
            'pickup_code' => (string) $order->pickup_code,
            'package_info' => (string) ($order->package_info ?? ''),
            'package_count' => (string) $order->package_count,
            'deliver_building' => (string) $order->deliver_building,
            'deliver_address' => (string) $order->deliver_address,
            'contact_name' => (string) $order->contact_name,
            'contact_phone' => (string) $order->contact_phone,
            'reward' => number_format($order->reward_cents / 100, 2, '.', ''),
            'expected_at' => $order->expected_at?->format('Y-m-d H:i') ?? '',
            'remark' => (string) ($order->remark ?? ''),
            'created_at' => $order->created_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i'),
            'campus_name' => (string) (Campus::query()->whereKey($order->campus_id)->value('name') ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sampleVariables(int $campusId): array
    {
        return [
            'order_no' => 'EP20260101120000ABCDEF',
            'pickup_site' => '菜鸟驿站（东门）',
            'pickup_code' => '8-2-3021',
            'package_info' => '纸箱小件',
            'package_count' => '1',
            'deliver_building' => '学生公寓 3 号楼',
            'deliver_address' => '3-412',
            'contact_name' => '张同学',
            'contact_phone' => '13800001234',
            'reward' => '5.00',
            'expected_at' => '18:00 前',
            'remark' => '轻拿轻放',
            'created_at' => now()->format('Y-m-d H:i'),
            'campus_name' => (string) (Campus::query()->whereKey($campusId)->value('name') ?? '示例校区'),
        ];
    }
}
