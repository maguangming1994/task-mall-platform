<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Services\TaskRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskRiskController extends Controller
{
    public function report(Request $request, Task $task, TaskRiskService $risk): JsonResponse
    {
        abort_unless(in_array($task->status, ['published', 'paused', 'closed'], true), 404);
        $data = $request->validate([
            'reason' => ['required', 'in:fraud,illegal,false_task,privacy,dangerous,diversion,other'],
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'evidence_file_ids' => ['sometimes', 'array', 'max:9'], 'evidence_file_ids.*' => ['string', 'size:26'],
        ]);
        $report = $risk->report($request->user(), $task, $data['reason'], $data['description'], $data['evidence_file_ids'] ?? [], $this->key($request));

        return ApiResponse::success(['id' => $report->public_id, 'status' => $report->status], 201);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
