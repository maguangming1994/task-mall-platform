<?php

namespace App\Modules\ExpressPickup\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\ExpressPickup\Models\ExpressPickupSettlement;
use App\Modules\ExpressPickup\Services\ExpressPickupSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminExpressPickupSettlementController extends Controller
{
    public function index(Request $request, ExpressPickupSettlementService $service): JsonResponse
    {
        $query = ExpressPickupSettlement::query()->with('items')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }
        if ($request->filled('campus_id')) {
            $query->where('campus_id', (int) $request->query('campus_id'));
        }
        $items = $query->limit(50)->get();

        return ApiResponse::success([
            'items' => $items->map(fn (ExpressPickupSettlement $settlement): array => $service->present($settlement))->all(),
        ]);
    }

    public function store(
        Request $request,
        ExpressPickupSettlementService $service,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
        ]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $campusId = $data['campus_id'] ?? $request->attributes->get('campus_database_id');
        if ($campusId !== null && ! is_int($campusId)) {
            throw ValidationException::withMessages(['campus_id' => ['校区参数不合法。']]);
        }
        $settlement = $service->build($admin->id, (string) $data['period_start'], (string) $data['period_end'], $campusId);
        $presented = $service->present($settlement);
        $audit->record($admin, 'express_pickup.settlement.build', 'express_pickup_settlement', $settlement->public_id, null, $presented, '生成跑腿结算批次。');

        return ApiResponse::success($presented, 201, '结算批次已生成。');
    }

    public function confirm(
        Request $request,
        ExpressPickupSettlement $settlement,
        ExpressPickupSettlementService $service,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($settlement);
        $confirmed = $service->confirm($settlement);
        $after = $service->present($confirmed);
        $audit->record($admin, 'express_pickup.settlement.confirm', 'express_pickup_settlement', $settlement->public_id, $before, $after, '确认跑腿结算批次。');

        return ApiResponse::success($after, 200, '结算批次已确认。');
    }

    public function pay(
        Request $request,
        ExpressPickupSettlement $settlement,
        ExpressPickupSettlementService $service,
        AuditLogger $audit,
    ): JsonResponse {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $service->present($settlement);
        $paid = $service->pay($admin->id, $settlement);
        $after = $service->present($paid);
        $audit->record($admin, 'express_pickup.settlement.pay', 'express_pickup_settlement', $settlement->public_id, $before, $after, '跑腿结算打款到钱包。');

        return ApiResponse::success($after, 200, '结算已打款到跑腿钱包。');
    }
}
