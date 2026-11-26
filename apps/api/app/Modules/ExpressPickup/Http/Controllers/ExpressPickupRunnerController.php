<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ExpressPickup\Models\ExpressPickupOrder;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use App\Modules\ExpressPickup\Services\ExpressPickupOrderService;
use App\Modules\ExpressPickup\Services\ExpressPickupRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExpressPickupRunnerController extends Controller
{
    public function apply(Request $request, ExpressPickupRunnerService $service): JsonResponse
    {
        $data = $request->validate([
            'real_name' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $runner = $service->apply($user, $data);

        return ApiResponse::success($service->present($runner), 201, '跑腿申请已提交，等待审核。');
    }

    public function profile(Request $request, ExpressPickupRunnerService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $runner = ExpressPickupRunner::query()
            ->where('campus_id', (int) $user->campus_id)
            ->where('user_id', $user->id)
            ->first();

        return ApiResponse::success(['runner' => $runner === null ? null : $service->present($runner)]);
    }

    public function hall(Request $request, ExpressPickupRunnerService $runners, ExpressPickupOrderService $orders): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assertRunner($user, $runners);
        $items = ExpressPickupOrder::query()
            ->where('campus_id', (int) $user->campus_id)
            ->where('status', ExpressPickupOrder::STATUS_PENDING_ACCEPT)
            ->where('user_id', '!=', $user->id)
            ->latest('id')
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'items' => $items->map(fn (ExpressPickupOrder $order): array => $orders->present($order, true))->all(),
        ]);
    }

    public function mine(Request $request, ExpressPickupOrderService $orders): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $query = ExpressPickupOrder::query()->where('runner_user_id', $user->id)->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return ApiResponse::success([
            'items' => $query->limit(50)->get()->map(fn (ExpressPickupOrder $order): array => $orders->present($order, false))->all(),
        ]);
    }

    public function accept(Request $request, ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accepted = $service->accept($user, $order);

        return ApiResponse::success($service->present($accepted, false), 200, '接单成功，请尽快取件。');
    }

    public function pickup(Request $request, ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($service->present($service->pickup($user, $order), false), 200, '已确认取件。');
    }

    public function deliver(Request $request, ExpressPickupOrder $order, ExpressPickupOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($service->present($service->deliver($user, $order), false), 200, '已标记送达，等待用户确认。');
    }

    private function assertRunner(User $user, ExpressPickupRunnerService $runners): void
    {
        if ($user->campus_id === null || ! $runners->isActiveRunner($user, (int) $user->campus_id)) {
            throw ValidationException::withMessages(['runner' => ['请先通过跑腿资格审核后再抢单。']]);
        }
    }
}
