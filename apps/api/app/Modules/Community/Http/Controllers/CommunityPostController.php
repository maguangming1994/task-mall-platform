<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunityPostController extends Controller
{
    private const CATEGORIES = [
        'experience' => '经验分享',
        'guide' => '实用攻略',
        'question' => '互助问答',
        'showcase' => '成果展示',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = CommunityPost::query()->where('status', 'published')
            ->when($data['category'] ?? null, fn ($query, $category) => $query->where('category_code', $category))
            ->orderByDesc('published_at')
            ->orderByDesc('id');
        $total = $query->count();
        $posts = $query->forPage($page, $size)->get();
        $likedPostIds = $request->user() === null ? collect() : DB::table('community_post_likes')
            ->where('user_id', $request->user()->id)->whereIn('community_post_id', $posts->pluck('id'))->pluck('community_post_id');

        return ApiResponse::success(['items' => $posts->map(fn (CommunityPost $post) => $this->data($post, likedByViewer: $likedPostIds->contains($post->id))), 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]]);
    }

    public function show(Request $request, CommunityPost $post): JsonResponse
    {
        abort_unless($post->status === 'published', 404);
        $liked = $request->user() !== null && DB::table('community_post_likes')->where('community_post_id', $post->id)->where('user_id', $request->user()->id)->exists();

        return ApiResponse::success($this->data($post, likedByViewer: $liked));
    }

    public function categories(): JsonResponse
    {
        return ApiResponse::success(['items' => collect(self::CATEGORIES)->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values()]);
    }

    public function media(UploadedFile $file): StreamedResponse
    {
        $isPublishedAttachment = DB::table('community_attachments as attachment')
            ->join('community_post_versions as version', 'version.id', '=', 'attachment.community_post_version_id')
            ->join('community_posts as post', 'post.id', '=', 'version.community_post_id')
            ->where('attachment.uploaded_file_id', $file->id)
            ->where('post.status', 'published')
            ->whereNull('post.deleted_at')
            ->whereColumn('post.published_version_no', 'version.version_no')
            ->exists();
        abort_unless($isPublishedAttachment && $file->purpose === 'community' && $file->status === 'clean', 404);

        abort_unless(Storage::disk($file->disk)->exists($file->quarantine_path), 404);

        return response()->stream(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->detected_mime ?? $file->declared_mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, CommunityContentService $service): JsonResponse
    {
        $data = $this->input($request);

        return ApiResponse::success($this->data($service->createPost($request->user(), $data, $this->key($request)), true, true), 201);
    }

    public function update(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        return ApiResponse::success($this->data($service->editPost($request->user(), $post, $this->input($request)), true, true));
    }

    public function destroy(Request $request, CommunityPost $post, CommunityContentService $service): JsonResponse
    {
        $service->deletePost($request->user(), $post);

        return ApiResponse::success(['deleted' => true]);
    }

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $query = CommunityPost::withTrashed()->where('user_id', $request->user()->id)->latest();
        $total = $query->count();

        return ApiResponse::success(['items' => $query->forPage($page, $size)->get()->map(fn (CommunityPost $post) => $this->data($post, true, true)), 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total]]);
    }

    public function adminIndex(): JsonResponse
    {
        return ApiResponse::success(['items' => CommunityPost::query()
            ->whereHas('versions', fn ($query) => $query->where('review_status', 'pending')->whereColumn('community_post_versions.version_no', 'community_posts.current_version_no'))
            ->latest()->limit(100)->get()->map(fn (CommunityPost $post) => $this->data($post, true))]);
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
            'category_code' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
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

    private function data(CommunityPost $post, bool $includeLatestVersion = false, bool $includeOwnerActions = false, bool $likedByViewer = false): array
    {
        $author = DB::table('users')->where('id', $post->user_id)->first();
        $latestVersion = $includeLatestVersion
            ? DB::table('community_post_versions')->where('community_post_id', $post->id)->where('version_no', $post->current_version_no)->first()
            : null;
        $latestVersionData = $latestVersion ? [
            'version_no' => (int) $latestVersion->version_no,
            'title' => $latestVersion->title,
            'content' => $latestVersion->content,
            'category_code' => $latestVersion->category_code,
            'review_status' => $latestVersion->review_status,
            'review_reason_code' => $latestVersion->review_reason_code,
            'review_note' => $latestVersion->review_note,
            'attachments' => $this->attachments($post, (int) $latestVersion->version_no),
        ] : null;

        return [
            'id' => $post->public_id,
            'author' => ['id' => $author?->public_id, 'nickname' => $author?->nickname ?: '社区用户'],
            'title' => $post->title,
            'content' => $post->content,
            'category_code' => $post->category_code,
            'category_label' => self::CATEGORIES[$post->category_code] ?? '经验分享',
            'status' => $post->trashed() ? 'deleted' : $post->status,
            'current_version_no' => $post->current_version_no,
            'published_version_no' => $post->published_version_no,
            'comment_count' => $post->comment_count,
            'like_count' => $post->like_count,
            'liked_by_me' => $likedByViewer,
            'reward_granted' => $post->reward_granted,
            'attachments' => $this->attachments($post, $post->published_version_no),
            'latest_version' => $latestVersionData,
            'can_edit' => $includeOwnerActions && ! $post->trashed() && $post->status !== 'hidden' && $latestVersion?->review_status !== 'pending',
            'can_delete' => $includeOwnerActions && ! $post->trashed(),
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at->toIso8601String(),
        ];
    }

    private function attachments(CommunityPost $post, ?int $versionNo): array
    {
        if ($versionNo === null) {
            return [];
        }

        return DB::table('community_attachments as attachment')
            ->join('community_post_versions as version', 'version.id', '=', 'attachment.community_post_version_id')
            ->join('uploaded_files as file', 'file.id', '=', 'attachment.uploaded_file_id')
            ->where('version.community_post_id', $post->id)
            ->where('version.version_no', $versionNo)
            ->where('file.status', 'clean')
            ->orderBy('attachment.sort_order')
            ->get(['file.public_id as file_id', 'attachment.file_type as type'])
            ->map(fn ($attachment) => [
                'file_id' => $attachment->file_id,
                'type' => $attachment->type,
                'url' => $versionNo === $post->published_version_no && $post->status === 'published' && ! $post->trashed()
                    ? "/community/media/{$attachment->file_id}"
                    : null,
            ])->all();
    }
}
