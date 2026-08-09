<?php

namespace App\Modules\Fulfillment\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Fulfillment\Services\FulfillmentService;
use App\Modules\Order\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfillmentController extends Controller
{
    public function store(Request $request, Order $order, FulfillmentService $service): JsonResponse
    {
        $d = $request->validate(['method' => ['required', 'in:logistics,no_logistics,virtual_delivery'], 'carrier_code' => ['nullable', 'string', 'max:40'], 'carrier_name' => ['nullable', 'string', 'max:80'], 'tracking_no' => ['nullable', 'string', 'max:80'], 'no_logistics_reason' => ['nullable', 'string', 'max:500'], 'virtual_delivery' => ['nullable', 'array'], 'items' => ['required', 'array', 'min:1'], 'items.*.order_item_id' => ['required', 'string', 'size:26'], 'items.*.quantity' => ['required', 'integer', 'min:1']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);
        $f = $service->create($admin, $order, $d, $key);

        return ApiResponse::success(['id' => $f->public_id, 'fulfillment_no' => $f->fulfillment_no, 'method' => $f->method, 'status' => $f->status, 'tracking_no' => $f->tracking_no, 'fulfilled_at' => $f->fulfilled_at->toIso8601String()], 201);
    }

    public function complete(Request $request, Order $order, FulfillmentService $service): JsonResponse
    {
        return ApiResponse::success(['id' => $service->complete($request->user(), $order)->public_id, 'status' => 'completed']);
    }
}
