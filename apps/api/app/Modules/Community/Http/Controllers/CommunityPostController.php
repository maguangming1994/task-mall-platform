<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Services\CommunityContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = CommunityPost::query()->where('status', 'published')->latest('published_at');
        $total = $query->count();

        return ApiResponse::success(['items' => $query->forPage($page, $size)->get()->map(fn (CommunityPost $post) => $this->data($post)), 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]]);
    }

    public function store(Request $request, CommunityContentService $service): JsonResponse
    {
        $data = $this->input($request);

        return ApiResponse::success($this->data($service->createPost($request->user(), $data, $this->key($request))), 201);
    }

    public function update(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        return ApiResponse::success($this->data($service->editPost($request->user(), $post, $this->input($request))));
    }

    public function destroy(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        $service->deletePost($request->user(), $post);

        return ApiResponse::success(['deleted' => true]);
    }

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success(['items' => CommunityPost::withTrashed()->where('user_id', $request->user()->id)->latest()->get()->map(fn (CommunityPost $post) => $this->data($post))]);
    }

    public function adminIndex(): JsonResponse
    {
        return ApiResponse::success(['items' => CommunityPost::query()->whereHas('versions', fn ($query) => $query->where('review_status', 'pending'))->latest()->limit(100)->get()->map(fn (CommunityPost $post) => $this->data($post))]);
    }

    public function review(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason_code' => ['nullable', 'required_if:decision,reject', 'string', 'max:64'], 'note' => ['required', 'string', 'max:500']]); /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($this->data($service->reviewPost($admin, $post, $data)));
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:20000'],
            'image_file_ids' => ['nullable', 'array', 'max:9'],
            'image_file_ids.*' => ['string', 'size:26'],
            'video_file_id' => ['nullable', 'string', 'size:26'],
        ]);
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);

        return $key;
    }

    private function data(CommunityPost $post): array
    {
        $author = DB::table('users')->where('id', $post->user_id)->first();

        return ['id' => $post->public_id, 'author' => ['id' => $author?->public_id, 'nickname' => $author?->nickname ?: '社区用户'], 'title' => $post->title, 'content' => $post->content, 'status' => $post->trashed() ? 'deleted' : $post->status, 'current_version_no' => $post->current_version_no, 'published_version_no' => $post->published_version_no, 'comment_count' => $post->comment_count, 'reward_granted' => $post->reward_granted, 'published_at' => $post->published_at?->toIso8601String(), 'created_at' => $post->created_at->toIso8601String()];
    }
}
