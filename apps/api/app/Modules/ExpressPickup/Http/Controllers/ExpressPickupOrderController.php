<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Services\ExpressPickupOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpressPickupOrderController extends Controller
{
    public function index(Request $request, ExpressPickupOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $query = ExpressPickupOrder::query()->where('user_id', $user->id)->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        $items = $query->limit(50)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (ExpressPickupOrder $order): array => $service->present($order, false))->all(),
        ]);
    }

    public function store(Request $request, ExpressPickupOrderService $service): JsonResponse
    {
        $data = $request->validate([
            'pickup_site' => ['required', 'string', 'max:100'],
            'pickup_code' => ['required', 'string', 'max:64'],
            'package_info' => ['nullable', 'string', 'max:200'],
            'package_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'deliver_building' => ['required', 'string', 'max:100'],
            'deliver_address' => ['required', 'string', 'max:200'],
            'contact_name' => ['required', 'string', 'max:50'],
            'contact_phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'reward_cents' => ['required', 'integer', 'min:100', 'max:10000'],
            'expected_at' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:300'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422, '缺少有效的 Idempotency-Key。');

        /** @var User $user */
        $user = $request->user();
        $order = $service->create($user, $data, $key);

        return ApiResponse::success($service->present($order, false), 201, '代取需求已创建，请完成支付。');
    }

    public function show(Request $request, ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($order->user_id === $user->id || $order->runner_user_id === $user->id, 404);

        return ApiResponse::success([
            ...$service->present($order, false),
            'events' => $order->events()->limit(30)->get()->map(fn ($event): array => [
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'event' => $event->event,
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function cancel(Request $request, ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        /** @var User $user */
        $user = $request->user();
        $cancelled = $service->cancel($user, $order, $data['reason'] ?? null);

        return ApiResponse::success($service->present($cancelled, false), 200, '代取需求已取消。');
    }

    public function confirm(Request $request, ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $completed = $service->confirm($user, $order);

        return ApiResponse::success($service->present($completed, false), 200, '已确认完成，感谢使用。');
    }
}
