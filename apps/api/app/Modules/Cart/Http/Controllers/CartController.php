<?php

namespace App\Modules\Cart\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartService;
use App\Modules\Product\Models\ProductSku;
use App\Modules\Store\Models\StoreProductSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request, CartService $service): JsonResponse
    {
        /** @var User $user */ $user = $request->user();

        return ApiResponse::success($service->present($user));
    }

    public function store(Request $request, CartService $service): JsonResponse
    {
        $data = $request->validate(['sku_id' => ['required', 'string', 'size:26'], 'purchase_mode' => ['required', 'in:cash,points,cash_points'], 'quantity' => ['required', 'integer', 'min:1']]);
        /** @var User $user */ $user = $request->user();
        $sku = ProductSku::query()->where('public_id', $data['sku_id'])->first();
        if ($sku !== null) {
            $service->add($user, $sku, $data['purchase_mode'], $data['quantity']);
        } else {
            $storeSku = StoreProductSku::query()->where('public_id', $data['sku_id'])->firstOrFail();
            if ($data['purchase_mode'] !== 'cash') {
                throw ValidationException::withMessages(['purchase_mode' => '门店商品仅支持现金购买。']);
            }
            $service->addStore($user, $storeSku, $data['quantity']);
        }

        return ApiResponse::success($service->present($user), 201);
    }

    public function update(Request $request, CartItem $cartItem, CartService $service): JsonResponse
    {
        $data = $request->validate(['quantity' => ['sometimes', 'integer', 'min:1'], 'selected' => ['sometimes', 'boolean']]);
        /** @var User $user */ $user = $request->user();
        $service->update($user, $cartItem, $data);

        return ApiResponse::success($service->present($user));
    }

    public function destroy(Request $request, CartItem $cartItem, CartService $service): JsonResponse
    {
        /** @var User $user */ $user = $request->user();
        $service->delete($user, $cartItem);

        return ApiResponse::success(null);
    }
}
