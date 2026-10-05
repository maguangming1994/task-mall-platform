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
    public function index(CommunityPost $post): JsonResponse
    {
        abort_unless($post->status === 'published', 404);

        return ApiResponse::success(['items' => CommunityComment::query()->where('community_post_id', $post->id)->where('status', 'published')->oldest()->get()->map(fn (CommunityComment $comment) => $this->data($comment))]);
    }

    public function store(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000'], 'parent_comment_id' => ['nullable', 'string', 'size:26']]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return ApiResponse::success($this->data($service->createComment($request->user(), $post, $data, $key)), 201);
    }

    public function adminIndex(): JsonResponse
    {
        return ApiResponse::success(['items' => CommunityComment::query()->where('status', 'pending_review')->latest()->limit(100)->get()->map(fn (CommunityComment $comment) => $this->data($comment))]);
    }

    public function review(Request $request, CommunityComment $comment, CommunityContentService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason_code' => ['nullable', 'required_if:decision,reject', 'string', 'max:64', 'in:spam,sexual,fraud,abuse,infringement,illegal,other'], 'note' => ['required', 'string', 'max:500']]); /** @var AdminUser $admin */
        $admin = $request->user();

        // 路由模型绑定可能早于校园 scope 中间件执行，此处借已生效的全局 scope 复核可见性，跨校访问维持 404
        abort_unless(CommunityComment::query()->whereKey($comment->id)->exists(), 404);

        // 终态保护：只有待审核评论可执行审核
        if ($comment->status !== 'pending_review') {
            return ApiResponse::error(
                'REVIEW_ALREADY_FINISHED',
                '该评论已经完成审核，不能重复操作。',
                409,
            );
        }

        $before = $this->data($comment);
        try {
            $reviewed = $service->reviewComment($admin, $comment, $data);
        } catch (ModelNotFoundException|ValidationException) {
            // 并发竞态：其他审核员已完成审核，统一返回 409 终态码
            return ApiResponse::error(
                'REVIEW_ALREADY_FINISHED',
                '该评论已经完成审核，不能重复操作。',
                409,
            );
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

        return ApiResponse::success($after);
    }

    private function data(CommunityComment $comment): array
    {
        $author = DB::table('users')->where('id', $comment->user_id)->first();
        $postId = DB::table('community_posts')->where('id', $comment->community_post_id)->value('public_id');
        $parentId = $comment->parent_comment_id ? DB::table('community_comments')->where('id', $comment->parent_comment_id)->value('public_id') : null;

        return ['id' => $comment->public_id, 'post_id' => $postId, 'parent_comment_id' => $parentId, 'author' => ['id' => $author?->public_id, 'nickname' => $author?->nickname ?: '社区用户'], 'content' => $comment->content, 'status' => $comment->status, 'reward_granted' => $comment->reward_granted, 'created_at' => $comment->created_at->toIso8601String()];
    }
}
