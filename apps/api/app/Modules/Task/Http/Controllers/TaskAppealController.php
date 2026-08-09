<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Services\TaskAppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskAppealController extends Controller
{
    public function store(Request $request, TaskClaim $taskClaim, TaskAppealService $appeals): JsonResponse
    {
        abort_unless($taskClaim->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
            'evidence_file_ids' => ['sometimes', 'array', 'max:9'], 'evidence_file_ids.*' => ['string', 'size:26'],
        ]);
        $appeal = $appeals->create($request->user(), $taskClaim, $data['reason'], $data['evidence_file_ids'] ?? [], $this->key($request));

        return ApiResponse::success($appeals->present($appeal), 201);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
