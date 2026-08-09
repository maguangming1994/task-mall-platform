<?php

namespace App\Modules\Order\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Order\Services\CheckoutOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function preview(Request $request, CheckoutOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($service->preview($user, $this->input($request, false), $this->platform($request)));
    }

    public function store(Request $request, CheckoutOrderService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $batch = $service->create($user, $this->input($request, true), $this->platform($request), $this->key($request));

        return ApiResponse::success($service->presentBatch($batch), 201);
    }

    private function input(Request $request, bool $tokenRequired): array
    {
        return $request->validate([
            'capability_version' => ['required', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku_id' => ['required', 'string', 'size:26'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.purchase_mode' => ['required', 'in:cash,points,cash_points'], 'group_options' => ['required', 'array', 'min:1', 'max:3'],
            'group_options.*.fulfillment_type' => ['required', 'in:logistics,no_logistics,virtual_delivery'],
            'group_options.*.address_id' => ['nullable', 'string', 'size:26'], 'group_options.*.coupon_id' => ['nullable', 'string', 'size:26'],
            'group_options.*.cash_payment_method' => ['nullable', 'in:wallet,wechat'],
            'preview_token' => [$tokenRequired ? 'required' : 'sometimes', 'string', 'size:64'],
        ]);
    }

    private function platform(Request $request): string
    {
        return validator(['platform' => $request->header('X-Client-Platform')], ['platform' => ['required', 'in:wechat-mini,h5-wechat,h5-browser']])->validate()['platform'];
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
