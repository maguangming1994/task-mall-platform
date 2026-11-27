<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Services\StoreManagerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreManagerStoreController extends Controller
{
    use ResolvesStoreContext;

    public function update(Request $request, StoreManagerAuthService $auth): JsonResponse
    {
        $this->assertCanManage($request);
        $store = $this->currentStore($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'logo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:1500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'contact_phone' => ['sometimes', 'string', 'regex:/^1[3-9]\d{9}$/|^0\d{2,3}-?\d{7,8}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_hours' => ['sometimes', 'nullable', 'string', 'max:60'],
            'shipping_fee_amount' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'free_shipping_threshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $identityFields = ['name', 'contact_phone'];
        foreach ($identityFields as $field) {
            if (array_key_exists($field, $data) && $this->currentMember($request)->role !== 'owner') {
                unset($data[$field]);
            }
        }
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }
        if (isset($data['contact_phone'])) {
            $data['contact_phone'] = trim((string) $data['contact_phone']);
        }
        $store->update($data);

        return ApiResponse::success($auth->presentStore($store->fresh()));
    }
}
