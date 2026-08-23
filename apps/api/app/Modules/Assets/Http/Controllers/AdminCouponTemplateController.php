<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\RewardBudgetPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminCouponTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(['items' => CouponTemplate::query()->latest('id')->get()->map(fn (CouponTemplate $template) => $this->present($template))]);
    }

    public function show(CouponTemplate $couponTemplate): JsonResponse
    {
        return ApiResponse::success($this->present($couponTemplate));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->definition($request);
        /** @var AdminUser $admin */ $admin = $request->user();
        $hash = $this->fingerprint($admin->id.':'.$this->key($request));
        $template = CouponTemplate::query()->where('idempotency_key_hash', $hash)->first();
        if ($template === null) {
            $template = CouponTemplate::query()->create([
                ...$data, 'minimum_cash_cents' => $data['minimum_cash_cents'] ?? 0, 'minimum_points' => $data['minimum_points'] ?? 0,
                'created_by_admin_id' => $admin->id, 'idempotency_key_hash' => $hash,
            ])->refresh();
            $audit->record($admin, 'coupon_template.create', 'coupon_template', $template->public_id, null, $this->present($template));
        }

        return ApiResponse::success($this->present($template), 201);
    }

    public function createVersion(Request $request, CouponTemplate $couponTemplate, AuditLogger $audit): JsonResponse
    {
        $data = $this->definition($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:500']])['reason'];
        /** @var AdminUser $admin */ $admin = $request->user();
        $hash = $this->fingerprint($admin->id.':'.$this->key($request));
        $existing = CouponTemplate::query()->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return ApiResponse::success($this->present($existing), 201);
        }

        $template = DB::transaction(function () use ($couponTemplate, $data, $admin, $hash): CouponTemplate {
            CouponTemplate::query()->lockForUpdate()->findOrFail($couponTemplate->id);

            return CouponTemplate::query()->create([
                ...$data,
                'minimum_cash_cents' => $data['minimum_cash_cents'] ?? 0,
                'minimum_points' => $data['minimum_points'] ?? 0,
                'issued_quantity' => 0,
                'status' => 'inactive',
                'version' => 1,
                'created_by_admin_id' => $admin->id,
                'idempotency_key_hash' => $hash,
            ]);
        });
        $audit->record(
            $admin,
            'coupon_template.create_version',
            'coupon_template',
            $template->public_id,
            $this->present($couponTemplate),
            $this->present($template),
            $reason,
        );

        return ApiResponse::success($this->present($template), 201);
    }

    public function status(Request $request, CouponTemplate $couponTemplate, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:enable,disable'],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);
        $this->key($request);
        /** @var AdminUser $admin */ $admin = $request->user();
        $target = $data['action'] === 'enable' ? 'active' : 'inactive';
        $before = $this->present($couponTemplate);

        $template = DB::transaction(function () use ($couponTemplate, $data, $target): CouponTemplate {
            $locked = CouponTemplate::query()->lockForUpdate()->findOrFail($couponTemplate->id);
            if ($locked->status === $target) {
                return $locked;
            }
            if ($locked->version !== $data['version']) {
                throw ValidationException::withMessages(['version' => ['优惠券模板已被其他操作更新，请刷新后重试。']]);
            }
            if ($target === 'active' && $locked->issued_quantity >= $locked->total_quantity) {
                throw ValidationException::withMessages(['status' => ['优惠券模板已经发完，不能重新启用。']]);
            }
            if ($target === 'inactive' && RewardBudgetPool::query()
                ->where('asset_type', 'coupon')
                ->where('coupon_template_public_id', $locked->public_id)
                ->where('locked_amount', '>', 0)
                ->exists()) {
                throw ValidationException::withMessages(['status' => ['仍有已发布任务锁定该模板的优惠券预算，请先结束任务并释放预算。']]);
            }
            $locked->update(['status' => $target, 'version' => $locked->version + 1]);

            return $locked->fresh();
        });
        $audit->record($admin, 'coupon_template.'.$data['action'], 'coupon_template', $template->public_id, $before, $this->present($template), $data['reason']);

        return ApiResponse::success($this->present($template));
    }

    /** @return array<string,mixed> */
    private function present(CouponTemplate $template): array
    {
        return [
            'id' => $template->public_id, 'name' => $template->name, 'cash_discount_cents' => $template->cash_discount_cents,
            'points_discount' => $template->points_discount, 'minimum_cash_cents' => $template->minimum_cash_cents,
            'minimum_points' => $template->minimum_points, 'valid_days' => $template->valid_days,
            'total_quantity' => $template->total_quantity, 'issued_quantity' => $template->issued_quantity,
            'remaining_quantity' => max(0, $template->total_quantity - $template->issued_quantity), 'status' => $template->status, 'version' => $template->version,
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function definition(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'cash_discount_cents' => ['required', 'integer', 'min:0'],
            'points_discount' => ['required', 'integer', 'min:0'], 'minimum_cash_cents' => ['sometimes', 'integer', 'min:0'],
            'minimum_points' => ['sometimes', 'integer', 'min:0'], 'valid_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'total_quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        if ($data['cash_discount_cents'] === 0 && $data['points_discount'] === 0) {
            throw ValidationException::withMessages(['cash_discount_cents' => ['优惠券至少需要一种抵扣价值。']]);
        }

        return $data;
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('admin_auth.hash_secret')));
    }
}
