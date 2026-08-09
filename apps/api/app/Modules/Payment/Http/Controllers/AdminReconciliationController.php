<?php

namespace App\Modules\Payment\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Payment\Models\ReconciliationEntry;
use App\Modules\Payment\Models\ReconciliationRun;
use App\Modules\Payment\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReconciliationController extends Controller
{
    public function index(ReconciliationService $service): JsonResponse
    {
        $items = ReconciliationRun::query()->latest('id')->get()->map(fn ($run) => $service->present($run));

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request, ReconciliationService $service): JsonResponse
    {
        $data = $request->validate(['business_date' => ['required', 'date_format:Y-m-d'], 'rows' => ['required', 'array', 'max:10000'], 'rows.*.payment_no' => ['required', 'string', 'max:40', 'distinct'], 'rows.*.provider_trade_no' => ['nullable', 'string', 'max:128'], 'rows.*.cash_amount' => ['required', 'integer', 'min:0'], 'rows.*.state' => ['required', 'in:SUCCESS,REFUND,CLOSED,REVOKED,PAYERROR']]);
        /** @var AdminUser $admin */ $admin = $request->user();

        return ApiResponse::success($service->present($service->import($admin, $data['business_date'], $data['rows'], $this->key($request))), 201);
    }

    public function show(ReconciliationRun $reconciliationRun, ReconciliationService $service): JsonResponse
    {
        return ApiResponse::success($service->present($reconciliationRun));
    }

    public function resolve(Request $request, ReconciliationEntry $reconciliationEntry, ReconciliationService $service): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:confirmed_channel_truth,confirmed_local_truth,ignored_with_reason'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $entry = $service->resolve($admin, $reconciliationEntry, $data['action'], $data['reason'], $this->key($request));

        return ApiResponse::success(['id' => $entry->public_id, 'result' => $entry->result, 'resolution_status' => $entry->resolution_status]);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422);

        return $key;
    }
}
