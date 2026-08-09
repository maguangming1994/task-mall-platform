<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\TaskAppeal;
use App\Modules\Task\Services\TaskAppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTaskAppealController extends Controller
{
    public function index(Request $request, TaskAppealService $appeals): JsonResponse
    {
        $status = $request->validate(['status' => ['sometimes', 'in:submitted,approved,rejected']])['status'] ?? 'submitted';
        $items = TaskAppeal::query()->with('claim')->where('status', $status)->latest('id')->get()->map(fn (TaskAppeal $appeal) => $appeals->present($appeal));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function review(Request $request, TaskAppeal $taskAppeal, TaskAppealService $appeals, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'min:1', 'max:1000']]);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = $appeals->present($taskAppeal);
        $reviewed = $appeals->review($admin, $taskAppeal, $data['decision'], $data['reason'], $this->key($request));
        $after = $appeals->present($reviewed);
        $audit->record($admin, 'task_appeal.'.$data['decision'], 'task_appeal', $taskAppeal->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
