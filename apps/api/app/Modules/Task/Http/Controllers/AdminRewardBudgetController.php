<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\RewardBudgetPool;
use App\Modules\Task\Services\RewardBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRewardBudgetController extends Controller
{
    public function couponTemplates(): JsonResponse
    {
        $items = CouponTemplate::query()->where('status', 'active')->orderBy('name')->get()->map(fn (CouponTemplate $template): array => [
            'id' => $template->public_id,
            'name' => $template->name,
            'cash_discount_cents' => $template->cash_discount_cents,
            'points_discount' => $template->points_discount,
            'minimum_cash_cents' => $template->minimum_cash_cents,
            'minimum_points' => $template->minimum_points,
            'valid_days' => $template->valid_days,
            'total_quantity' => $template->total_quantity,
            'issued_quantity' => $template->issued_quantity,
            'remaining_quantity' => max(0, $template->total_quantity - $template->issued_quantity),
            'status' => $template->status,
            'version' => $template->version,
            'created_at' => $template->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function index(Request $request, RewardBudgetService $service): JsonResponse
    {
        $items = RewardBudgetPool::query()->latest('id')->get()->map(fn ($pool) => $service->present($pool));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function store(Request $request, RewardBudgetService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'], 'asset_type' => ['required', 'in:points,coupon,wallet_cash'],
            'coupon_template_id' => ['sometimes', 'nullable', 'string', 'size:26'], 'total_amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        $key = $this->key($request);
        /** @var AdminUser $admin */ $admin = $request->user();
        $pool = $service->create($admin, $data, $key);
        $presented = $service->present($pool);
        $audit->record($admin, 'reward_budget_pool.create', 'reward_budget_pool', $pool->public_id, null, $presented, $data['reason']);

        return ApiResponse::success($presented, 201);
    }

    public function adjust(Request $request, RewardBudgetPool $budgetPool, RewardBudgetService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:increase,decrease'], 'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'], 'version' => ['required', 'integer', 'min:1'],
        ]);
        $key = $this->key($request);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $service->present($budgetPool);
        $after = $service->present($service->adjust($admin, $budgetPool, $data, $key));
        $audit->record($admin, 'reward_budget_pool.adjust', 'reward_budget_pool', $budgetPool->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
