<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Product\Models\ProductSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInventoryController extends Controller
{
    public function index(InventoryService $service): JsonResponse
    {
        $items = Inventory::query()->with('sku.product')->get()->map(fn ($inventory) => $service->present($inventory));

        return ApiResponse::success(['items' => $items]);
    }

    public function adjust(Request $request, ProductSku $sku, InventoryService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:increase,decrease,set'], 'quantity' => ['required', 'integer', 'min:0'], 'version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $inventory = $service->adjust($admin, $sku, $data['action'], $data['quantity'], $data['version'], $data['reason'], $this->key($request));
        $presented = $service->present($inventory);
        $audit->record($admin, 'inventory.'.$data['action'], 'sku', $sku->public_id, null, $presented, $data['reason']);

        return ApiResponse::success($presented);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
