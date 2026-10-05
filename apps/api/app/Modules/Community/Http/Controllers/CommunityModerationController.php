<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\ContentAppeal;
use App\Modules\Community\Models\ContentReport;
use App\Modules\Community\Models\ModerationAction;
use App\Modules\Community\Services\CommunityModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityModerationController extends Controller
{
    public function report(Request $request, string $contentType, string $contentId, CommunityModerationService $service): JsonResponse
    {
        abort_unless(in_array($contentType, ['post', 'comment'], true), 404);
        $data = $request->validate(['reason_code' => ['required', 'in:spam,sexual,fraud,abuse,infringement,illegal,other'], 'description' => ['nullable', 'string', 'max:500'], 'evidence_file_ids' => ['nullable', 'array', 'max:9'], 'evidence_file_ids.*' => ['string', 'size:26']]);

        return ApiResponse::success($this->reportData($service->report($request->user(), $contentType, $contentId, $data, $this->key($request))), 201);
    }

    public function appeal(Request $request, ModerationAction $action, CommunityModerationService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'evidence_file_ids' => ['nullable', 'array', 'max:9'], 'evidence_file_ids.*' => ['string', 'size:26']]);

        return ApiResponse::success($this->appealData($service->appeal($request->user(), $action, $data, $this->key($request))), 201);
    }

    public function reports(): JsonResponse
    {
        return ApiResponse::success(['items' => ContentReport::query()->where('status', 'submitted')->latest()->limit(100)->get()->map(fn (ContentReport $report) => $this->reportData($report))]);
    }

    public function appeals(): JsonResponse
    {
        return ApiResponse::success(['items' => ContentAppeal::query()->where('status', 'submitted')->latest()->limit(100)->get()->map(fn (ContentAppeal $appeal) => $this->appealData($appeal))]);
    }

    public function mine(Request $request): JsonResponse
    {
        $actions = ModerationAction::query()->where('content_author_user_id', $request->user()->id)->latest()->limit(100)->get();

        return ApiResponse::success(['items' => $actions->map(function (ModerationAction $action) {
            $appeal = ContentAppeal::query()->where('moderation_action_id', $action->id)->first();

            return [...$this->actionData($action), 'appeal' => $appeal ? $this->appealData($appeal) : null];
        })]);
    }

    public function moderate(Request $request, string $contentType, string $contentId, CommunityModerationService $service, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($contentType, ['post', 'comment'], true), 404);
        $data = $request->validate([
            'decision' => ['required', 'in:warning,hide,delete'],
            'reason_code' => ['required', 'in:spam,sexual,fraud,abuse,infringement,illegal,other'],
            'note' => ['required', 'string', 'max:500'],
            'restrictions' => ['nullable', 'array', 'max:4'],
            'restrictions.*' => ['in:post,comment,mute,account'],
            'restriction_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]); /** @var AdminUser $admin */
        $admin = $request->user();
        $action = $service->moderate($admin, $contentType, $contentId, $data, $this->key($request));

        // 幂等键命中时服务返回既有处置单，不重复写审计
        if ($action->wasRecentlyCreated) {
            $audit->record(
                $admin,
                'community.moderate.'.$data['decision'],
                'community_'.$contentType,
                $contentId,
                null,
                $this->actionData($action),
                $data['note'].'（原因码：'.$data['reason_code'].'）',
            );
        }

        return ApiResponse::success($this->actionData($action));
    }

    public function reviewAppeal(Request $request, ContentAppeal $appeal, CommunityModerationService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['required', 'string', 'max:500']]); /** @var AdminUser $admin */
        $admin = $request->user();

        // 终态保护：只有已提交的申诉可复核
        if ($appeal->status !== 'submitted') {
            return ApiResponse::error(
                'APPEAL_ALREADY_FINISHED',
                '该申诉已经完成复核，不能重复操作。',
                409,
            );
        }

        $before = $this->appealData($appeal);
        $reviewed = $service->reviewAppeal($admin, $appeal, $data);
        $after = $this->appealData($reviewed);
        $audit->record(
            $admin,
            'community.appeal.'.$data['decision'],
            'community_appeal',
            $appeal->public_id,
            $before,
            $after,
            $data['note'],
        );

        return ApiResponse::success($after);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return $key;
    }

    private function reportData(ContentReport $report): array
    {
        $model = $report->content_type === 'post' ? CommunityPost::class : CommunityComment::class;
        $contentId = $model::query()->whereKey($report->content_id)->value('public_id');

        return ['id' => $report->public_id, 'content_type' => $report->content_type, 'content_id' => $contentId, 'reason_code' => $report->reason_code, 'description' => $report->description, 'status' => $report->status, 'created_at' => $report->created_at->toIso8601String()];
    }

    private function actionData(ModerationAction $action): array
    {
        // content_id 对外解析为公开 ULID，与 reportData 口径一致，不泄露内部自增 ID
        $contentModel = $action->content_type === 'post' ? CommunityPost::class : CommunityComment::class;
        $contentPublicId = $contentModel::query()->whereKey($action->content_id)->value('public_id');

        return ['id' => $action->public_id, 'content_type' => $action->content_type, 'content_id' => $contentPublicId, 'action_type' => $action->action_type, 'reason_code' => $action->reason_code, 'status' => $action->status, 'reward_revoked' => $action->reward_revoked, 'appeal_deadline_at' => $action->appeal_deadline_at?->toIso8601String(), 'created_at' => $action->created_at->toIso8601String()];
    }

    private function appealData(ContentAppeal $appeal): array
    {
        $actionId = ModerationAction::query()->whereKey($appeal->moderation_action_id)->value('public_id');

        return ['id' => $appeal->public_id, 'action_id' => $actionId, 'reason' => $appeal->reason, 'status' => $appeal->status, 'review_note' => $appeal->review_note, 'created_at' => $appeal->created_at->toIso8601String()];
    }
}
