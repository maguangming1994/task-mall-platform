<?php

namespace App\Modules\Account\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Account\Models\UserAddress;
use App\Modules\Account\Services\UserAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = UserAddress::query()->where('user_id', $request->user()->id)->orderByDesc('is_default')->latest('updated_at')->get()->map(fn ($item) => $this->present($item));

        return ApiResponse::success($items);
    }

    public function store(Request $request, UserAddressService $addresses): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        if (strlen($key) < 12 || strlen($key) > 128) {
            return ApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', '请提供 12 至 128 字符的幂等键。', 422);
        }

        return ApiResponse::success($this->present($addresses->create($request->user(), $this->validateAddress($request), $key)), 201);
    }

    public function update(Request $request, UserAddress $address, UserAddressService $addresses): JsonResponse
    {
        return ApiResponse::success($this->present($addresses->update($request->user(), $address, $this->validateAddress($request, true))));
    }

    public function destroy(Request $request, UserAddress $address, UserAddressService $addresses): Response
    {
        $addresses->delete($request->user(), $address);

        return response()->noContent();
    }

    /** @return array<string,mixed> */
    private function validateAddress(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'recipient_name' => [$required, 'string', 'min:1', 'max:80'], 'phone' => [$required, 'string', 'min:6', 'max:32'],
            'province' => [$required, 'string', 'min:1', 'max:80'], 'city' => [$required, 'string', 'min:1', 'max:80'],
            'district' => [$required, 'string', 'min:1', 'max:80'], 'detail' => [$required, 'string', 'min:1', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'], 'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> */
    private function present(UserAddress $address): array
    {
        return [
            'id' => $address->public_id, 'recipient_name' => $address->recipient_name, 'phone' => $address->phone,
            'province' => $address->province, 'city' => $address->city, 'district' => $address->district,
            'detail' => $address->detail, 'postal_code' => $address->postal_code, 'is_default' => $address->is_default,
        ];
    }
}
