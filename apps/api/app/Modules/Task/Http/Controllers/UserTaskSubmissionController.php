<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskSubmission;
use App\Modules\Task\Services\TaskSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserTaskSubmissionController extends Controller
{
    public function index(Request $request, TaskClaim $taskClaim, TaskSubmissionService $submissions): JsonResponse
    {
        abort_unless($taskClaim->user_id === $request->user()->id, 404);
        $items = $taskClaim->submissions()->oldest('submission_no')->get()->map(fn (TaskSubmission $submission) => $submissions->present($submission));

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request, TaskClaim $taskClaim, TaskSubmissionService $submissions): JsonResponse
    {
        abort_unless($taskClaim->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'text' => ['sometimes', 'nullable', 'string', 'max:10000'], 'fields' => ['sometimes', 'array'],
            'file_ids' => ['sometimes', 'array', 'max:20'], 'file_ids.*' => ['string', 'size:26'],
            'content' => ['sometimes', 'array'], 'content.text' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'content.fields' => ['sometimes', 'array'], 'content.image_file_ids' => ['sometimes', 'array', 'max:9'],
            'content.image_file_ids.*' => ['string', 'size:26'], 'content.video_file_ids' => ['sometimes', 'array', 'max:1'],
            'content.video_file_ids.*' => ['string', 'size:26'], 'content.file_ids' => ['sometimes', 'array', 'max:5'],
            'content.file_ids.*' => ['string', 'size:26'],
        ]);
        $nested = $data['content'] ?? [];
        $fileIds = array_values(array_unique([
            ...($data['file_ids'] ?? []), ...($nested['image_file_ids'] ?? []), ...($nested['video_file_ids'] ?? []), ...($nested['file_ids'] ?? []),
        ]));
        $submission = $submissions->submit(
            $request->user(), $taskClaim,
            ['text' => $nested['text'] ?? $data['text'] ?? null, 'fields' => $nested['fields'] ?? $data['fields'] ?? []],
            $fileIds, $this->key($request),
        );

        return ApiResponse::success($submissions->present($submission), 201);
    }

    public function withdraw(Request $request, TaskSubmission $taskSubmission, TaskSubmissionService $submissions): JsonResponse
    {
        abort_unless($taskSubmission->user_id === $request->user()->id, 404);
        $reason = $request->validate(['reason' => ['sometimes', 'string', 'max:500']])['reason'] ?? '用户主动撤回提交。';

        return ApiResponse::success($submissions->present($submissions->withdraw($request->user(), $taskSubmission, $reason, $this->key($request))));
    }

    public function rewards(Request $request, TaskClaim $taskClaim): JsonResponse
    {
        abort_unless($taskClaim->user_id === $request->user()->id, 404);
        $items = $taskClaim->rewardDeliveries()->orderBy('id')->get()->map(fn ($delivery) => [
            'id' => $delivery->public_id, 'asset_type' => $delivery->asset_type, 'amount' => $delivery->amount,
            'status' => $delivery->status, 'attempt_count' => $delivery->attempt_count,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
        ]);

        return ApiResponse::success(['reward_status' => $taskClaim->reward_status, 'items' => $items]);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12 && strlen($key) <= 128, 422, 'Idempotency-Key is required.');

        return $key;
    }
}
