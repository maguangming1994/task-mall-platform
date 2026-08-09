<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Inventory\Services\ShippingTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingQuoteController extends Controller
{
    public function show(Request $request, ShippingTemplateService $service): JsonResponse
    {
        $data = $request->validate(['address_id' => ['required', 'string', 'size:26']]);
        /** @var User $user */ $user = $request->user();
        $address = UserAddress::query()->where('public_id', $data['address_id'])->firstOrFail();

        return ApiResponse::success($service->quoteCart($user, $address));
    }
}
