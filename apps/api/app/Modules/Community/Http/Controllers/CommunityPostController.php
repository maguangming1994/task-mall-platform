<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Campus\Models\Campus;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostFavorite;
use App\Modules\Community\Models\CommunityPostVersion;
use App\Modules\Community\Models\UserFollow;
use App\Modules\Community\Services\CommunityContentService;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    private const FEEDS = ['discover', 'following', 'nearby', 'featured'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
            'feed' => ['nullable', Rule::in(self::FEEDS)],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;
        $feed = $data['feed'] ?? 'discover';
        $viewer = $request->user();

        $query = CommunityPost::query()->where('status', 'published')
            ->when($data['category'] ?? null, fn ($query, $category) => $query->where('category_code', $category));

        if ($feed === 'following') {
            abort_unless($viewer !== null, 401);
            $followingIds = UserFollow::query()->where('follower_id', $viewer->id)->pluck('following_id')->all();
            if ($followingIds === []) {
                return ApiResponse::success(['items' => [], 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => 0]]);
            }
            $query->whereIn('user_id', $followingIds)->orderByDesc('published_at')->orderByDesc('id');
        } elseif ($feed === 'nearby') {
            abort_unless($viewer !== null, 401);
            $campus = $viewer->campus_id !== null ? Campus::query()->find($viewer->campus_id) : null;
            $nearbyUserIds = User::query()
                ->when($campus !== null, fn ($q) => $q->where('campus_id', $campus->id))
                ->pluck('id')->all();
            if ($nearbyUserIds === []) {
                return ApiResponse::success(['items' => [], 'pagination' => ['page' => $page, 'page_size' => $size, 'total' => 0]]);
            }
            $query->whereIn('user_id', $nearbyUserIds)->orderByDesc('published_at')->orderByDesc('id');
        } elseif ($feed === 'featured') {
            $query->orderByRaw('(like_count + comment_count * 2) desc, published_at desc');
        } else {
            $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $total = $query->count();
        $posts = $query->forPage($page, $size)->get();
        $meta = $this->viewerMeta($request, $posts);

        return ApiResponse::success([
            'items' => $posts->map(fn (CommunityPost $post) => $this->data($post, meta: $meta)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
        ]);
    }

    public function show(Request $request, CommunityPost $post): JsonResponse
    {
        abort_unless($post->status === 'published', 404);

        return ApiResponse::success($this->data($post, meta: $this->viewerMeta($request, collect([$post]))));
    }

    public function categories(CommunityContentService $service): JsonResponse
    {
        return ApiResponse::success([
            'items' => collect(self::CATEGORIES)->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values(),
            'publishing_rules' => $service->publishingRules(),
        ]);
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

    public function adminIndex(Request $request): JsonResponse
    {
        // 待审核帖子列表：预载作者资料（含实名状态），供移动端/后台展示「已实名」标识
        $posts = CommunityPost::query()
            ->whereHas('versions', fn ($query) => $query->where('review_status', 'pending')->whereColumn('community_post_versions.version_no', 'community_posts.current_version_no'))
            ->latest()->limit(100)->get();
        $meta = $this->viewerMeta($request, $posts);

        return ApiResponse::success(['items' => $posts->map(fn (CommunityPost $post) => $this->data($post, true, false, $meta))]);
    }

    public function review(Request $request, CommunityPost $post, CommunityContentService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason_code' => ['nullable', 'required_if:decision,reject', 'string', 'max:64', 'in:spam,sexual,fraud,abuse,infringement,illegal,other'], 'note' => ['required', 'string', 'max:500']]); /** @var AdminUser $admin */
        $admin = $request->user();

        // 路由模型绑定可能早于校园 scope 中间件执行，此处借已生效的全局 scope 复核可见性，跨校访问维持 404
        abort_unless(CommunityPost::query()->whereKey($post->id)->exists(), 404);

        // 终态保护：当前版本不在待审核状态时拒绝重复审核
        $pendingVersionExists = CommunityPostVersion::query()
            ->where('community_post_id', $post->id)
            ->where('version_no', $post->current_version_no)
            ->where('review_status', 'pending')
            ->exists();
        if (! $pendingVersionExists) {
            return ApiResponse::error(
                'REVIEW_ALREADY_FINISHED',
                '该帖子当前版本已经完成审核，不能重复操作。',
                409,
            );
        }

        $before = $this->data($post, true);
        try {
            $reviewed = $service->reviewPost($admin, $post, $data);
        } catch (ModelNotFoundException) {
            // 并发竞态：其他审核员已完成该版本审核，统一返回 409 终态码（否则服务层抛 404）
            return ApiResponse::error(
                'REVIEW_ALREADY_FINISHED',
                '该帖子当前版本已经完成审核，不能重复操作。',
                409,
            );
        }
        $after = $this->data($reviewed, true);
        $audit->record(
            $admin,
            'community.post.'.$data['decision'],
            'community_post',
            $post->public_id,
            $before,
            $after,
            $data['note'].(! empty($data['reason_code']) ? '（原因码：'.$data['reason_code'].'）' : ''),
        );

        return ApiResponse::success($after);
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:20000'],
            'category_code' => ['nullable', Rule::in(array_keys(self::CATEGORIES))],
            'location_name' => ['nullable', 'string', 'max:80'],
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

    /**
     * 批量预加载当前请求 viewer 对这批帖子的互动状态与作者信息，避免 N+1。
     *
     * @param  Collection<int,CommunityPost>  $posts
     * @return array{liked:array<int,bool>,favorited:array<int,bool>,authors:array<int,array<string,mixed>>}
     */
    private function viewerMeta(Request $request, $posts): array
    {
        $viewer = $request->user();
        $postIds = $posts->pluck('id')->all();
        $userIds = $posts->pluck('user_id')->unique()->values()->all();

        $liked = [];
        $favorited = [];
        $followedIds = [];
        if ($viewer !== null) {
            $liked = DB::table('community_post_likes')
                ->where('user_id', $viewer->id)->whereIn('community_post_id', $postIds)
                ->pluck('community_post_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
            $favorited = CommunityPostFavorite::query()
                ->where('user_id', $viewer->id)->whereIn('community_post_id', $postIds)
                ->pluck('community_post_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
            $followedIds = UserFollow::query()
                ->where('follower_id', $viewer->id)->whereIn('following_id', $userIds)
                ->pluck('following_id')->all();
        }

        $users = User::query()->whereIn('id', $userIds)->get(['id', 'public_id', 'nickname', 'avatar_file_id', 'campus_id']);
        $avatarIds = $users->pluck('avatar_file_id')->filter()->unique()->all();
        $avatarFiles = UploadedFile::query()->whereIn('id', $avatarIds)->get();
        $verifiedUserIds = UserIdentityVerification::query()
            ->whereIn('user_id', $userIds)->where('status', 'verified')
            ->pluck('user_id')->unique()->all();
        $campusNames = Campus::query()
            ->whereIn('id', $users->pluck('campus_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $authors = [];
        foreach ($users as $user) {
            $avatar = $avatarFiles->firstWhere('id', $user->avatar_file_id);
            $authors[(int) $user->id] = [
                'id' => $user->public_id,
                'nickname' => $user->nickname ?: '社区用户',
                'avatar_url' => $avatar === null ? null : rtrim((string) config('app.url'), '/').route('user-avatars.show', ['file' => $avatar], false),
                'campus_name' => $user->campus_id !== null ? ($campusNames[$user->campus_id] ?? null) : null,
                'verified' => in_array($user->id, $verifiedUserIds, true),
                'is_followed' => in_array($user->id, $followedIds, true),
                'is_self' => $viewer !== null && $user->id === $viewer->id,
            ];
        }

        return ['liked' => $liked, 'favorited' => $favorited, 'authors' => $authors];
    }

    private function data(CommunityPost $post, bool $includeLatestVersion = false, bool $includeOwnerActions = false, array $meta = []): array
    {
        $author = $meta['authors'][$post->user_id] ?? null;
        if ($author === null) {
            $fallback = DB::table('users')->where('id', $post->user_id)->first();
            $author = ['id' => $fallback?->public_id, 'nickname' => $fallback?->nickname ?: '社区用户', 'avatar_url' => null, 'campus_name' => null, 'verified' => false, 'is_followed' => false, 'is_self' => false];
        }
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
            'author' => $author,
            'title' => $post->title,
            'content' => $post->content,
            'category_code' => $post->category_code,
            'category_label' => self::CATEGORIES[$post->category_code] ?? '经验分享',
            'location_name' => $post->location_name,
            'status' => $post->trashed() ? 'deleted' : $post->status,
            'current_version_no' => $post->current_version_no,
            'published_version_no' => $post->published_version_no,
            'comment_count' => $post->comment_count,
            'like_count' => $post->like_count,
            'liked_by_me' => (bool) ($meta['liked'][$post->id] ?? false),
            'favorite_count' => (int) ($post->favorite_count ?? 0),
            'favorited_by_me' => (bool) ($meta['favorited'][$post->id] ?? false),
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
