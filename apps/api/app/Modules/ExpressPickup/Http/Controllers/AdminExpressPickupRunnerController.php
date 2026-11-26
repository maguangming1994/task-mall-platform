<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\ExpressPickup\Models\ExpressPickupRunner;
use App\Modules\ExpressPickup\Services\ExpressPickupRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminExpressPickupRunnerController extends Controller
{
    public function index(Request $request, ExpressPickupRunnerService $service): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));

        $query = ExpressPickupRunner::query()->with('user')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('campus_id')) {
            $query->where('campus_id', (int) $request->query('campus_id'));
        }

        $total = $query->count();
        $items = $query->forPage($page, $pageSize)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (ExpressPickupRunner $runner): array => $service->present($runner))->all(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $pageSize)),
            ],
        ]);
    }

    public function review(
        Request $request,
        ExpressPickupRunner $runner,
        ExpressPickupRunnerService $service,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', 'in:active,rejected,suspended'],
            'reason' => ['required_if:status,rejected,suspended', 'nullable', 'string', 'max:200'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($runner);
        $updated = $service->review($admin->id, $runner, $data['status'], $data['reason'] ?? null);
        $after = $service->present($updated);
        $audit->record($admin, 'express_pickup.runner.review', 'express_pickup_runner', $runner->public_id, $before, $after, $data['reason'] ?? null);

        return ApiResponse::success($after, 200, '跑腿资格状态已更新。');
    }
}
