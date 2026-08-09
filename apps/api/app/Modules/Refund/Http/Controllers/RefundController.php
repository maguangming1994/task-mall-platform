<?php

namespace App\Modules\Refund\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Order\Models\Order;
use App\Modules\Refund\Models\Refund;
use App\Modules\Refund\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function store(Request $q, RefundService $s): JsonResponse
    {
        $d = $q->validate([
            'order_id' => ['required', 'string', 'size:26'],
            'service_type' => ['nullable', 'in:refund_only,return_and_refund'],
            'reason_code' => ['required', 'string', 'max:64'],
            'return_method' => ['nullable', 'in:logistics,no_logistics'],
            'description' => ['nullable', 'string', 'max:500'],
            'evidence_file_ids' => ['nullable', 'array', 'max:9'],
            'evidence_file_ids.*' => ['string', 'size:26'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'string', 'size:26'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $o = Order::query()->where('public_id', $d['order_id'])->firstOrFail();
        $k = (string) $q->header('Idempotency-Key');
        abort_unless(strlen($k) >= 12, 422);
        $r = $s->create($q->user(), $o, $d, $k);

        return ApiResponse::success($this->data($r), 201);
    }

    public function index(Request $q): JsonResponse
    {
        return ApiResponse::success(['items' => Refund::query()->where('user_id', $q->user()->id)->latest()->get()->map(fn ($r) => $this->data($r))]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'string', 'max:24']])['status'] ?? null;
        $query = Refund::query()->latest();
        if ($status) {
            $query->where('status', $status);
        }

        return ApiResponse::success(['items' => $query->limit(100)->get()->map(fn (Refund $refund) => $this->data($refund))]);
    }

    public function review(Request $q, Refund $refund, RefundService $s): JsonResponse
    {
        $d = $q->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required', 'string', 'max:500'],
            'responsibility_party' => ['nullable', 'in:buyer,seller,carrier,negotiated,pending'],
            'return_shipping_payer' => ['nullable', 'in:buyer,seller,carrier,negotiated,pending'],
            'return_method' => ['nullable', 'in:logistics,no_logistics'],
        ]); /** @var AdminUser $a */
        $a = $q->user();
        if ($d['decision'] === 'reject') {
            $refund->update(['status' => 'rejected', 'reviewed_by_admin_id' => $a->id, 'review_note' => $d['note']]);
            $r = $refund;
        } else {
            $r = $s->approve($a, $refund, $d['note'], $d);
        }

        return ApiResponse::success($this->data($r));
    }

    public function submitReturn(Request $request, Refund $refund, RefundService $service): JsonResponse
    {
        $data = $request->validate([
            'carrier_code' => ['required', 'string', 'max:64'],
            'tracking_no' => ['required', 'string', 'max:100'],
            'paid_shipping_amount' => ['nullable', 'integer', 'min:0'],
            'payment_proof_file_id' => ['nullable', 'string', 'size:26'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return ApiResponse::success($this->data($service->submitReturn($request->user(), $refund, $data, $key)));
    }

    public function receiveReturn(Request $request, Refund $refund, RefundService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]); /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($this->data($service->receiveReturn($admin, $refund, $data['note'])));
    }

    private function data(Refund $r): array
    {
        return ['id' => $r->public_id, 'refund_no' => $r->refund_no, 'service_type' => $r->service_type, 'reason_code' => $r->reason_code, 'reason_name' => $r->reason_name_snapshot, 'status' => $r->status, 'refund_cash_amount' => $r->refund_cash_amount, 'refund_wallet_amount' => $r->refund_wallet_amount, 'refund_external_amount' => $r->refund_external_amount, 'refund_points' => $r->refund_points, 'responsibility_party' => $r->responsibility_party, 'return_shipping_payer' => $r->return_shipping_payer, 'return_method' => $r->return_method, 'user_action_deadline_at' => $r->user_action_deadline_at?->toIso8601String(), 'original_shipping_refunded' => $r->original_shipping_refunded, 'created_at' => $r->created_at->toIso8601String()];
    }
}
