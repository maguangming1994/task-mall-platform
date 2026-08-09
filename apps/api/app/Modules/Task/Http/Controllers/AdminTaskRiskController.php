<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Task\Models\TaskRiskCase;
use App\Modules\Task\Services\TaskRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTaskRiskController extends Controller
{
    public function index(Request $request, TaskRiskService $risk): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:open,reviewing,resolved,appealed,closed'], 'risk_level' => ['sometimes', 'in:low,medium,high'], 'overdue_only' => ['sometimes', 'boolean']]);
        $query = TaskRiskCase::query();
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['risk_level'])) {
            $query->where('risk_level', $data['risk_level']);
        }
        if ($data['overdue_only'] ?? false) {
            $query->whereIn('status', ['open', 'reviewing'])->where('response_due_at', '<=', now());
        }
        $items = $query->orderByRaw("CASE risk_level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->orderBy('response_due_at')->get()->map(fn (TaskRiskCase $case) => $risk->present($case));

        return ApiResponse::success(['items' => $items, 'pagination' => ['page' => 1, 'page_size' => $items->count(), 'total' => $items->count()]]);
    }

    public function review(Request $request, TaskRiskCase $taskRiskCase, TaskRiskService $risk, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:clear,request_more_information,confirm_violation'], 'actions' => ['sometimes', 'array'],
            'actions.*' => ['in:hide,stop_claiming,freeze_reward,reject_submission,restrict_user,revoke_reward,restore,compensate,suspend_publisher,revoke_publish_permission'],
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = $risk->present($taskRiskCase);
        $reviewed = $risk->review($admin, $taskRiskCase, $data['decision'], $data['actions'] ?? [], $data['reason'], $this->key($request));
        $after = $risk->present($reviewed);
        $audit->record($admin, 'task_risk.'.$data['decision'], 'task_risk_case', $taskRiskCase->public_id, $before, $after, $data['reason']);

        return ApiResponse::success($after);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
