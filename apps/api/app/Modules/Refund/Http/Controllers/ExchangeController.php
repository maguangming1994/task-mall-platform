<?php

namespace App\Modules\Refund\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Refund\Models\Exchange;
use App\Modules\Refund\Models\ReturnShippingCompensation;
use App\Modules\Refund\Services\ExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(['items' => Exchange::query()->where('user_id', $request->user()->id)->latest()->get()->map(fn (Exchange $exchange) => $this->data($exchange))]);
    }

    public function store(Request $request, ExchangeService $service): JsonResponse
    {
        $data = $request->validate([
            'order_item_id' => ['required', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', 'string', 'max:64'],
            'return_method' => ['required', 'in:logistics,no_logistics'],
            'description' => ['nullable', 'string', 'max:500'],
            'evidence_file_ids' => ['nullable', 'array', 'max:9'],
            'evidence_file_ids.*' => ['string', 'size:26'],
        ]);
        $key = $this->key($request);

        return ApiResponse::success($this->data($service->create($request->user(), $data, $key)), 201);
    }

    public function show(Request $request, Exchange $exchange): JsonResponse
    {
        abort_unless($exchange->user_id === $request->user()->id, 404);

        return ApiResponse::success($this->data($exchange));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'string', 'max:24']])['status'] ?? null;
        $query = Exchange::query()->latest();
        if ($status) {
            $query->where('status', $status);
        }

        return ApiResponse::success(['items' => $query->limit(100)->get()->map(fn (Exchange $exchange) => $this->data($exchange))]);
    }

    public function submitReturn(Request $request, Exchange $exchange, ExchangeService $service): JsonResponse
    {
        $data = $request->validate([
            'carrier_code' => ['required', 'string', 'max:64'],
            'tracking_no' => ['required', 'string', 'max:100'],
            'paid_shipping_amount' => ['nullable', 'integer', 'min:0'],
            'payment_proof_file_id' => ['nullable', 'string', 'size:26'],
        ]);

        return ApiResponse::success($this->data($service->submitReturn($request->user(), $exchange, $data, $this->key($request))));
    }

    public function review(Request $request, Exchange $exchange, ExchangeService $service): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'responsibility_party' => ['nullable', 'in:buyer,seller,carrier,negotiated,pending'],
            'return_shipping_payer' => ['nullable', 'in:buyer,seller,carrier,negotiated,pending'],
            'replacement_shipping_payer' => ['nullable', 'in:buyer,seller,carrier,negotiated,pending'],
            'return_method' => ['nullable', 'in:logistics,no_logistics'],
            'reason' => ['required', 'string', 'max:500'],
        ]); /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($this->data($service->review($admin, $exchange, $data)));
    }

    public function fulfill(Request $request, Exchange $exchange, ExchangeService $service): JsonResponse
    {
        $data = $request->validate([
            'fulfillment_type' => ['required', 'in:logistics,no_logistics'],
            'carrier_code' => ['nullable', 'string', 'max:64'],
            'tracking_no' => ['nullable', 'string', 'max:100'],
            'no_logistics_note' => ['nullable', 'string', 'max:500'],
        ]); /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($this->data($service->fulfillReplacement($admin, $exchange, $data, $this->key($request))));
    }

    public function receiveReturn(Request $request, Exchange $exchange, ExchangeService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return ApiResponse::success($this->data($service->receiveReturn($exchange, $data['note'])));
    }

    public function complete(Request $request, Exchange $exchange, ExchangeService $service): JsonResponse
    {
        return ApiResponse::success($this->data($service->confirmReplacement($request->user(), $exchange)));
    }

    public function cancel(Request $request, Exchange $exchange, ExchangeService $service): JsonResponse
    {
        return ApiResponse::success($this->data($service->cancel($request->user(), $exchange)));
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return $key;
    }

    private function data(Exchange $exchange): array
    {
        $shipments = $exchange->shipments()->orderBy('id')->get()->map(function ($shipment) {
            $compensation = ReturnShippingCompensation::query()->where('source_type', 'exchange_return')->where('source_id', $shipment->id)->first();

            return ['id' => $shipment->public_id, 'direction' => $shipment->direction, 'method' => $shipment->method, 'carrier_code' => $shipment->carrier_code, 'tracking_no' => $shipment->tracking_no, 'no_logistics_note' => $shipment->no_logistics_note, 'paid_shipping_amount' => $shipment->paid_shipping_amount, 'status' => $shipment->status, 'shipped_at' => $shipment->shipped_at?->toIso8601String(), 'received_at' => $shipment->received_at?->toIso8601String(), 'compensation' => $compensation ? ReturnShippingCompensationController::data($compensation) : null];
        });

        return ['id' => $exchange->public_id, 'exchange_no' => $exchange->exchange_no, 'order_item_id' => $exchange->order_item_id, 'quantity' => $exchange->quantity, 'reason_code' => $exchange->reason_code, 'reason_name' => $exchange->reason_name_snapshot, 'status' => $exchange->status, 'responsibility_party' => $exchange->responsibility_party, 'return_shipping_payer' => $exchange->return_shipping_payer, 'replacement_shipping_payer' => $exchange->replacement_shipping_payer, 'return_method' => $exchange->return_method, 'review_note' => $exchange->review_note, 'return_deadline_at' => $exchange->return_deadline_at?->toIso8601String(), 'replacement_auto_complete_at' => $exchange->replacement_auto_complete_at?->toIso8601String(), 'shipments' => $shipments, 'created_at' => $exchange->created_at->toIso8601String()];
    }
}
