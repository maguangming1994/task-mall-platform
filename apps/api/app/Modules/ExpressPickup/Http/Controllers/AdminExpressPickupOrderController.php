<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\PrinterPrintJob;
use App\Modules\ExpressPickup\Services\ExpressPickupOrderService;
use App\Modules\ExpressPickup\Services\ReceiptPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminExpressPickupOrderController extends Controller
{
    public function index(Request $request, ExpressPickupOrderService $service): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));

        $query = ExpressPickupOrder::query()->with('runner')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('campus_id')) {
            $query->where('campus_id', (int) $request->query('campus_id'));
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->query('keyword'));
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('order_no', 'like', '%'.$keyword.'%')
                    ->orWhere('contact_name', 'like', '%'.$keyword.'%')
                    ->orWhere('contact_phone', 'like', '%'.$keyword.'%');
            });
        }

        $total = $query->count();
        $items = $query->forPage($page, $pageSize)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (ExpressPickupOrder $order): array => $service->present($order, true))->all(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $pageSize)),
            ],
        ]);
    }

    public function show(ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        $order->loadMissing('runner');

        return ApiResponse::success([
            ...$service->present($order, false),
            'events' => $order->events()->limit(50)->get()->map(fn ($event): array => [
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'event' => $event->event,
                'actor_type' => $event->actor_type,
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'print_jobs' => PrinterPrintJob::query()
                ->where('business_type', 'express_pickup_order')
                ->where('business_id', $order->public_id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (PrinterPrintJob $job): array => [
                    'id' => $job->public_id,
                    'status' => $job->status,
                    'error_message' => $job->error_message,
                    'printed_at' => $job->printed_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function cancel(
        Request $request,
        ExpressPickupOrder $order,
        ExpressPickupOrderService $service,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($order, false);
        $cancelled = $service->adminCancel($admin->id, $order, $data['reason']);
        $after = $service->present($cancelled, false);
        $audit->record($admin, 'express_pickup.order.cancel', 'express_pickup_order', $order->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after, 200, '代取单已取消。');
    }

    public function reprint(
        Request $request,
        ExpressPickupOrder $order,
        ReceiptPrintService $receipts,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        try {
            $job = $receipts->reprint($order);
        } catch (Throwable $exception) {
            return ApiResponse::error('PRINT_FAILED', $exception->getMessage(), 422);
        }
        $audit->record($admin, 'express_pickup.order.reprint', 'express_pickup_order', $order->public_id, null, ['job_id' => $job->public_id], '管理员补打代取小票。');

        return ApiResponse::success(['job_id' => $job->public_id, 'status' => $job->status], 200, '小票已发送打印。');
    }
}
