<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Inventory\Models\ShippingTemplate;
use App\Modules\Inventory\Services\ShippingTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminShippingTemplateController extends Controller
{
    public function index(ShippingTemplateService $service): JsonResponse
    {
        return ApiResponse::success(['items' => ShippingTemplate::query()->latest('id')->get()->map(fn ($template) => $service->present($template))]);
    }

    public function store(Request $request, ShippingTemplateService $service): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($service->present($service->create($admin, $this->definition($request, false), $this->key($request))), 201);
    }

    public function update(Request $request, ShippingTemplate $template, ShippingTemplateService $service): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($service->present($service->update($admin, $template, $this->definition($request, true))));
    }

    private function definition(Request $request, bool $versionRequired): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'charge_type' => ['required', 'in:fixed,by_quantity,by_weight'], 'fixed_amount' => ['nullable', 'integer', 'min:0'],
            'first_unit' => ['nullable', 'numeric', 'gt:0'], 'first_amount' => ['nullable', 'integer', 'min:0'], 'additional_unit' => ['nullable', 'numeric', 'gt:0'], 'additional_amount' => ['nullable', 'integer', 'min:0'],
            'regions' => ['required', 'array', 'min:1'], 'regions.*.region_codes' => ['required', 'array', 'min:1'], 'regions.*.region_codes.*' => ['string', 'max:32'],
            'regions.*.deliverable' => ['required', 'boolean'], 'regions.*.remote_surcharge_amount' => ['required', 'integer', 'min:0'],
            'free_rules' => ['present', 'array'], 'free_rules.*.type' => ['required', 'in:product,sku,cash_threshold,quantity_threshold'], 'free_rules.*.target_ids' => ['sometimes', 'array'],
            'free_rules.*.threshold_amount' => ['sometimes', 'integer', 'min:0'], 'free_rules.*.threshold_quantity' => ['sometimes', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'], 'version' => [$versionRequired ? 'required' : 'sometimes', 'integer', 'min:1'],
        ]);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
