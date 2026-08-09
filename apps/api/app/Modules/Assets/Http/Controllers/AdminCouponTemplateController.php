<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\CouponTemplate;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCouponTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(['items' => CouponTemplate::query()->latest('id')->get()->map(fn (CouponTemplate $template) => $this->present($template))]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'cash_discount_cents' => ['required', 'integer', 'min:0'],
            'points_discount' => ['required', 'integer', 'min:0'], 'minimum_cash_cents' => ['sometimes', 'integer', 'min:0'],
            'minimum_points' => ['sometimes', 'integer', 'min:0'], 'valid_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'total_quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);
        if ($data['cash_discount_cents'] === 0 && $data['points_discount'] === 0) {
            abort(422, '优惠券至少需要一种抵扣价值。');
        }
        /** @var AdminUser $admin */ $admin = $request->user();
        $hash = $this->fingerprint($admin->id.':'.$this->key($request));
        $template = CouponTemplate::query()->where('idempotency_key_hash', $hash)->first();
        if ($template === null) {
            $template = CouponTemplate::query()->create([
                ...$data, 'minimum_cash_cents' => $data['minimum_cash_cents'] ?? 0, 'minimum_points' => $data['minimum_points'] ?? 0,
                'created_by_admin_id' => $admin->id, 'idempotency_key_hash' => $hash,
            ]);
            $audit->record($admin, 'coupon_template.create', 'coupon_template', $template->public_id, null, $this->present($template));
        }

        return ApiResponse::success($this->present($template), 201);
    }

    /** @return array<string,mixed> */
    private function present(CouponTemplate $template): array
    {
        return [
            'id' => $template->public_id, 'name' => $template->name, 'cash_discount_cents' => $template->cash_discount_cents,
            'points_discount' => $template->points_discount, 'minimum_cash_cents' => $template->minimum_cash_cents,
            'minimum_points' => $template->minimum_points, 'valid_days' => $template->valid_days,
            'total_quantity' => $template->total_quantity, 'issued_quantity' => $template->issued_quantity,
            'remaining_quantity' => $template->total_quantity - $template->issued_quantity, 'status' => $template->status, 'version' => $template->version,
        ];
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
