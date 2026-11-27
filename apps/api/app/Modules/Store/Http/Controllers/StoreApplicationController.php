<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\StoreApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreApplicationController extends Controller
{
    public function index(Request $request, StoreApplicationService $service): JsonResponse
    {
        return ApiResponse::success(['items' => $service->presentMine($request->user())]);
    }

    public function store(Request $request, StoreApplicationService $service): JsonResponse
    {
        $data = $this->validateInput($request);
        $store = $service->apply($request->user(), $data);

        return ApiResponse::success($this->present($store), 201, '开店申请已提交，等待平台审核。');
    }

    public function update(Request $request, Store $store, StoreApplicationService $service): JsonResponse
    {
        $data = $this->validateInput($request, true);
        $updated = $service->update($store, $request->user(), $data);

        return ApiResponse::success($this->present($updated));
    }

    public function resubmit(Request $request, Store $store, StoreApplicationService $service): JsonResponse
    {
        $updated = $service->resubmit($store, $request->user());

        return ApiResponse::success($this->present($updated), 200, '已重新提交，等待平台审核。');
    }

    /** @return array<string, mixed> */
    private function validateInput(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:80'],
            'logo_url' => ['sometimes', 'nullable', 'url:http,https', 'max:1500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'contact_phone' => [$required, 'string', 'regex:/^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_hours' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Store $store): array
    {
        return [
            'id' => $store->public_id,
            'name' => $store->name,
            'logo_url' => $store->logo_url,
            'description' => $store->description,
            'contact_phone' => $store->contact_phone,
            'address' => $store->address,
            'business_hours' => $store->business_hours,
            'status' => $store->status,
            'reject_reason' => $store->reject_reason,
            'applied_at' => $store->applied_at?->toIso8601String(),
            'reviewed_at' => $store->reviewed_at?->toIso8601String(),
            'created_at' => $store->created_at?->toIso8601String(),
        ];
    }
}
