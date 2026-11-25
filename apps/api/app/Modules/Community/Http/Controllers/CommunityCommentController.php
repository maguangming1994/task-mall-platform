<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Community\Models\CommunityComment;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Services\CommunityContentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommunityCommentController extends Controller
{
    public function index(Request $request, string $post): JsonResponse
    {
        $model = CommunityPost::query()->withoutGlobalScope('campus_context')->where('public_id', $post)->first();
        abort_unless($model !== null && $model->status === 'published' && $model->isVisibleTo($request->user()), 404);

        return ApiResponse::success(['items' => CommunityComment::query()->where('community_post_id', $model->id)->where('status', 'published')->oldest()->get()->map(fn (CommunityComment $comment) => $this->data($comment))]);
    }

    public function store(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000'], 'parent_comment_id' => ['nullable', 'string', 'size:26']]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return ApiResponse::success($this->data($service->createComment($request->user(), $post, $data, $key)), 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'id' => ['nullable', 'string', 'max:26'],
            'keyword' => ['nullable', 'string', 'max:120'],
            'audit_status' => ['nullable', 'in:pending_review,published,rejected,hidden,deleted'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);
        $base = CommunityComment::withTrashed();
        $query = clone $base;
        if (! empty($data['id'])) {
            $query->where('public_id', 'like', '%'.$data['id'].'%');
        }
        if (! empty($data['keyword'])) {
            $keyword = '%'.$data['keyword'].'%';
            $query->where(function ($query) use ($keyword): void {
                $query->where('content', 'like', $keyword)
                    ->orWhereExists(function ($users) use ($keyword): void {
                        $users->selectRaw('1')->from('users')
                            ->whereColumn('users.id', 'community_comments.user_id')
                            ->where('nickname', 'like', $keyword);
                    })
                    ->orWhereExists(function ($posts) use ($keyword): void {
                        $posts->selectRaw('1')->from('community_posts')
                            ->whereColumn('community_posts.id', 'community_comments.community_post_id')
                            ->where('title', 'like', $keyword);
                    });
            });
        }
        if (! empty($data['audit_status'])) {
            $this->applyAdminStatus($query, $data['audit_status']);
        }
        $total = $query->count();
        $comments = $query->latest('id')->forPage($page, $size)->get();
        $stats = ['total' => (clone $base)->count()];
        foreach (['pending_review', 'published', 'rejected', 'hidden', 'deleted'] as $status) {
            $statusQuery = clone $base;
            $this->applyAdminStatus($statusQuery, $status);
            $stats[$status] = $statusQuery->count();
        }

        return ApiResponse::success([
            'items' => $comments->map(fn (CommunityComment $comment) => $this->data($comment))->values(),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size))],
            'stats' => $stats,
        ]);
    }

    public function review(Request $request, CommunityComment $comment, CommunityContentService $service, AuditLogger $audit): JsonResponse
    {
        $data = $this->reviewInput($request);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $result = $this->performReview($admin, $comment, $data, $service, $audit);
        if (! $result['success']) {
            return ApiResponse::error($result['code'], $result['message'], $result['status']);
        }

        return ApiResponse::success($result['item']);
    }

    public function batchReview(Request $request, CommunityContentService $service, AuditLogger $audit): JsonResponse
    {
        $data = $this->reviewInput($request, true);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $items = [];
        foreach ($data['ids'] as $id) {
            $comment = CommunityComment::query()->where('public_id', $id)->first();
            $result = $comment === null
                ? ['success' => false, 'code' => 'NOT_FOUND', 'message' => '评论不存在或无权操作。', 'status' => 404]
                : $this->performReview($admin, $comment, $data, $service, $audit);
            $items[] = [
                'id' => $id,
                'success' => $result['success'],
                'code' => $result['code'] ?? null,
                'message' => $result['message'] ?? '审核完成。',
                'item' => $result['item'] ?? null,
            ];
        }

        return ApiResponse::success(['items' => $items]);
    }

    /** @return array<string,mixed> */
    private function reviewInput(Request $request, bool $batch = false): array
    {
        return $request->validate([
            'ids' => [$batch ? 'required' : 'exclude', 'array', 'min:1', 'max:50'],
            'ids.*' => ['string', 'size:26', 'distinct'],
            'decision' => ['required', 'in:approve,reject'],
            'reason_code' => ['nullable', 'required_if:decision,reject', 'string', 'max:64', 'in:spam,sexual,fraud,abuse,infringement,illegal,other'],
            'note' => ['required', 'string', 'max:500'],
        ]);
    }

    /** @return array<string,mixed> */
    private function performReview(AdminUser $admin, CommunityComment $comment, array $data, CommunityContentService $service, AuditLogger $audit): array
    {
        // 路由模型绑定可能早于校园 scope 中间件执行，跨校访问维持 404 语义。
        if (! CommunityComment::query()->whereKey($comment->id)->exists()) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'message' => '评论不存在或无权操作。', 'status' => 404];
        }
        if ($comment->status !== 'pending_review') {
            return ['success' => false, 'code' => 'REVIEW_ALREADY_FINISHED', 'message' => '该评论已经完成审核，不能重复操作。', 'status' => 409];
        }

        $before = $this->data($comment);
        try {
            $reviewed = $service->reviewComment($admin, $comment, $data);
        } catch (ModelNotFoundException|ValidationException) {
            return ['success' => false, 'code' => 'REVIEW_ALREADY_FINISHED', 'message' => '该评论已经完成审核，不能重复操作。', 'status' => 409];
        }
        $after = $this->data($reviewed);
        $audit->record(
            $admin,
            'community.comment.'.$data['decision'],
            'community_comment',
            $comment->public_id,
            $before,
            $after,
            $data['note'].(! empty($data['reason_code']) ? '（原因码：'.$data['reason_code'].'）' : ''),
        );

        return ['success' => true, 'item' => $after];
    }

    private function applyAdminStatus($query, string $status): void
    {
        if ($status === 'deleted') {
            $query->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhere('status', 'deleted'));

            return;
        }
        $query->whereNull('deleted_at')->where('status', $status);
    }

    private function data(CommunityComment $comment): array
    {
        $author = DB::table('users')->where('id', $comment->user_id)->first();
        $post = DB::table('community_posts')->where('id', $comment->community_post_id)->first(['public_id', 'title']);
        $parentId = $comment->parent_comment_id ? DB::table('community_comments')->where('id', $comment->parent_comment_id)->value('public_id') : null;
        $status = $comment->trashed() ? 'deleted' : $comment->status;

        return [
            'id' => $comment->public_id,
            'post_id' => $post?->public_id,
            'post_title' => $post?->title,
            'parent_comment_id' => $parentId,
            'author' => ['id' => $author?->public_id, 'nickname' => $author?->nickname ?: '社区用户'],
            'content' => $comment->content,
            'status' => $status,
            'audit_status' => $status,
            'reward_granted' => $comment->reward_granted,
            'review_reason_code' => $comment->review_reason_code,
            'review_note' => $comment->review_note,
            'reviewed_at' => $comment->reviewed_at?->toIso8601String(),
            'published_at' => $comment->published_at?->toIso8601String(),
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}
