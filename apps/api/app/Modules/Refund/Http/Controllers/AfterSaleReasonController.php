<?php

namespace App\Modules\Refund\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Refund\Models\AfterSaleReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfterSaleReasonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $serviceType = $request->validate(['service_type' => ['nullable', 'in:refund_only,return_and_refund,exchange']])['service_type'] ?? null;
        $query = AfterSaleReason::query()->where('enabled', true)->orderBy('sort_order');
        if ($serviceType) {
            $query->whereJsonContains('service_types', $serviceType);
        }

        return ApiResponse::success(['items' => $query->get()->map(fn (AfterSaleReason $reason) => $this->data($reason))]);
    }

    public function adminIndex(): JsonResponse
    {
        return ApiResponse::success(['items' => AfterSaleReason::query()->orderBy('sort_order')->get()->map(fn (AfterSaleReason $reason) => $this->data($reason))]);
    }

    public function update(Request $request, AfterSaleReason $reason): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'service_types' => ['required', 'array', 'min:1'],
            'service_types.*' => ['in:refund_only,return_and_refund,exchange'],
            'default_responsibility' => ['required', 'in:buyer,seller,carrier,negotiated,pending'],
            'return_shipping_payer' => ['required', 'in:buyer,seller,carrier,negotiated,pending'],
            'evidence_required' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        abort_if($reason->version !== $data['version'], 409);
        $reason->update([...$data, 'version' => $reason->version + 1]);

        return ApiResponse::success($this->data($reason->fresh()));
    }

    private function data(AfterSaleReason $reason): array
    {
        return ['id' => $reason->public_id, 'code' => $reason->code, 'name' => $reason->name, 'service_types' => $reason->service_types, 'default_responsibility' => $reason->default_responsibility, 'return_shipping_payer' => $reason->return_shipping_payer, 'evidence_required' => $reason->evidence_required, 'enabled' => $reason->enabled, 'sort_order' => $reason->sort_order, 'version' => $reason->version];
    }
}
