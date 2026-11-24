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
use App\Modules\Robot\Models\RobotPostForwardLog;
use App\Modules\Robot\Services\RobotForwardDispatchService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunityPostController extends Controller
{
    private const FEEDS = ['discover', 'following', 'nearby', 'featured'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category' => ['nullable', Rule::in(array_keys(CommunityPost::CATEGORIES))],
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
            'items' => collect(CommunityPost::CATEGORIES)->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values(),
            'publishing_rules' => $service->publishingRules(),
            'post_reward_points' => $service->postRewardPoints(),
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
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'id' => ['nullable', 'string', 'max:26'],
            'keyword' => ['nullable', 'string', 'max:120'],
            'category_code' => ['nullable', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'audit_status' => ['nullable', Rule::in(['pending_review', 'published', 'rejected', 'hidden', 'deleted'])],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['page_size'] ?? 20);
        $base = CommunityPost::withTrashed();
        $query = clone $base;
        if (! empty($data['id'])) {
            $query->where('public_id', 'like', '%'.$data['id'].'%');
        }
        if (! empty($data['keyword'])) {
            $keyword = '%'.$data['keyword'].'%';
            $query->where(function ($query) use ($keyword): void {
                $query->whereHas('versions', fn ($version) => $version
                    ->whereColumn('community_post_versions.version_no', 'community_posts.current_version_no')
                    ->where(fn ($text) => $text->where('title', 'like', $keyword)->orWhere('content', 'like', $keyword)))
                    ->orWhereExists(function ($users) use ($keyword): void {
                        $users->selectRaw('1')->from('users')
                            ->whereColumn('users.id', 'community_posts.user_id')
                            ->where('nickname', 'like', $keyword);
                    });
            });
        }
        if (! empty($data['category_code'])) {
            $query->whereHas('versions', fn ($version) => $version
                ->whereColumn('community_post_versions.version_no', 'community_posts.current_version_no')
                ->where('category_code', $data['category_code']));
        }
        if (! empty($data['audit_status'])) {
            $this->applyAdminStatus($query, $data['audit_status']);
        }
        $total = $query->count();
        $posts = $query->latest('id')->forPage($page, $size)->get();
        $meta = $this->viewerMeta($request, $posts);
        $forwards = $this->forwardSummaries($posts);
        $stats = ['total' => (clone $base)->count()];
        foreach (['pending_review', 'published', 'rejected', 'hidden', 'deleted'] as $status) {
            $statusQuery = clone $base;
            $this->applyAdminStatus($statusQuery, $status);
            $stats[$status] = $statusQuery->count();
        }

        return ApiResponse::success([
            'items' => $posts->map(function (CommunityPost $post) use ($meta, $forwards): array {
                $item = $this->data($post, true, false, $meta);
                $item['forward'] = $forwards[(int) $post->id] ?? ['status' => 'none', 'groups' => []];

                return $item;
            })->values(),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size))],
            'stats' => $stats,
        ]);
    }

    /** 汇总当前页帖子在各转发群的最新结果：none / success / failed。 */
    private function forwardSummaries($posts): array
    {
        $ids = $posts->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        if ($ids === []) {
            return [];
        }

        $summaries = [];
        foreach ($ids as $id) {
            $summaries[$id] = ['status' => 'none', 'groups' => []];
        }

        $logs = RobotPostForwardLog::query()
            ->whereIn('post_id', $ids)
            ->orderByDesc('forwarded_at')
            ->orderByDesc('id')
            ->get(['post_id', 'group_id', 'group_name', 'status', 'error_message', 'manual', 'forwarded_at']);

        foreach ($logs as $log) {
            $postId = (int) $log->post_id;
            $groups = $summaries[$postId]['groups'];
            if (array_key_exists($log->group_id, $groups)) {
                continue;
            }
            $groups[$log->group_id] = [
                'group_id' => $log->group_id,
                'group_name' => $log->group_name,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'manual' => (bool) $log->manual,
                'forwarded_at' => $log->forwarded_at?->toIso8601String(),
            ];
            $summaries[$postId]['groups'] = $groups;
        }

        foreach ($summaries as $id => $summary) {
            if ($summary['groups'] === []) {
                continue;
            }
            $summaries[$id]['groups'] = array_values($summary['groups']);
            $summaries[$id]['status'] = collect($summary['groups'])
                ->every(fn (array $group): bool => $group['status'] === 'success') ? 'success' : 'failed';
        }

        return $summaries;
    }

    public function forward(Request $request, CommunityPost $post, RobotForwardDispatchService $dispatch, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $result = $dispatch->forwardPost($admin, $post);
        $audit->record(
            $admin,
            'community.post.forward',
            'community_post',
            $post->public_id,
            null,
            $result,
            '后台手动重发帖子到转发群。',
        );

        return ApiResponse::success($result);
    }

    public function review(Request $request, CommunityPost $post, CommunityContentService $service, AuditLogger $audit): JsonResponse
    {
        $data = $this->reviewInput($request);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $result = $this->performReview($admin, $post, $data, $service, $audit);
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
        foreach (array_unique($data['ids']) as $id) {
            $post = CommunityPost::query()->where('public_id', $id)->first();
            $result = $post === null
                ? ['success' => false, 'code' => 'NOT_FOUND', 'message' => '帖子不存在或无权操作。', 'status' => 404]
                : $this->performReview($admin, $post, $data, $service, $audit);
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

    public function authors(Request $request): JsonResponse
    {
        $data = $request->validate(['keyword' => ['nullable', 'string', 'max:64']]);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        $users = User::query()
            ->where('status', 'active')
            ->when($keyword !== '', fn ($query) => $query->where(fn ($sub) => $sub
                ->where('nickname', 'like', '%'.$keyword.'%')
                ->orWhere('public_id', $keyword)))
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'public_id', 'nickname']);

        return ApiResponse::success(['items' => $users->map(fn (User $user) => [
            'id' => $user->public_id,
            'nickname' => $user->nickname ?: '社区用户',
        ])->values()]);
    }

    public function adminStore(Request $request, CommunityContentService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'author_id' => ['required', 'string', 'size:26'],
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:20000'],
            'category_code' => ['nullable', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'location_name' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);
        $author = User::query()->where('public_id', $data['author_id'])->first();
        if ($author === null || $author->status !== 'active') {
            return ApiResponse::error('AUTHOR_NOT_FOUND', '作者不存在、不在当前校区或账号不可用。', 404);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $data['idempotency_key'] = (string) $request->header('Idempotency-Key');
        try {
            $post = $service->createPostAsAdmin($admin, $author, $data);
        } catch (ValidationException $exception) {
            return ApiResponse::error('POST_CREATE_REJECTED', (string) collect($exception->errors())->flatten()->first(), 422);
        }
        $after = $this->data($post);
        $audit->record(
            $admin,
            'community.post.admin_create',
            'community_post',
            $post->public_id,
            null,
            $after,
            trim((string) ($data['admin_note'] ?? '')) ?: '后台运营代发帖子。',
        );

        return ApiResponse::success($after, 201);
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
    private function performReview(AdminUser $admin, CommunityPost $post, array $data, CommunityContentService $service, AuditLogger $audit): array
    {
        // 路由模型绑定可能早于校园 scope 中间件执行，跨校访问维持 404 语义。
        if (! CommunityPost::query()->whereKey($post->id)->exists()) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'message' => '帖子不存在或无权操作。', 'status' => 404];
        }
        $pendingVersionExists = CommunityPostVersion::query()
            ->where('community_post_id', $post->id)
            ->where('version_no', $post->current_version_no)
            ->where('review_status', 'pending')
            ->exists();
        if (! $pendingVersionExists) {
            return ['success' => false, 'code' => 'REVIEW_ALREADY_FINISHED', 'message' => '该帖子当前版本已经完成审核，不能重复操作。', 'status' => 409];
        }

        if ($data['decision'] === 'approve' && in_array($post->status, ['hidden', 'deleted'], true)) {
            return ['success' => false, 'code' => 'REVIEW_STATE_CONFLICT', 'message' => '帖子已隐藏或删除，不能审核发布。', 'status' => 409];
        }

        $before = $this->data($post, true);
        try {
            $reviewed = $service->reviewPost($admin, $post, $data);
        } catch (ValidationException $exception) {
            return ['success' => false, 'code' => 'REVIEW_STATE_CONFLICT', 'message' => (string) collect($exception->errors())->flatten()->first(), 'status' => 409];
        } catch (ModelNotFoundException) {
            return ['success' => false, 'code' => 'REVIEW_ALREADY_FINISHED', 'message' => '该帖子当前版本已经完成审核，不能重复操作。', 'status' => 409];
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

        return ['success' => true, 'item' => $after];
    }

    private function applyAdminStatus($query, string $status): void
    {
        if ($status === 'deleted') {
            $query->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhere('status', 'deleted'));

            return;
        }
        $query->whereNull('deleted_at')->where('status', '<>', 'deleted');
        if ($status === 'hidden') {
            $query->where('status', 'hidden');

            return;
        }
        $query->where('status', '<>', 'hidden');
        $reviewStatus = ['pending_review' => 'pending', 'rejected' => 'rejected', 'published' => 'approved'][$status];
        $query->whereHas('versions', fn ($version) => $version
            ->whereColumn('community_post_versions.version_no', 'community_posts.current_version_no')
            ->where('review_status', $reviewStatus));
        if ($status === 'published') {
            $query->where('status', 'published');
        }
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:20000'],
            'category_code' => ['nullable', Rule::in(array_keys(CommunityPost::CATEGORIES))],
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
            'reviewed_at' => $latestVersion->reviewed_at,
            'attachments' => $this->attachments($post, (int) $latestVersion->version_no),
        ] : null;
        $auditStatus = $post->trashed() || $post->status === 'deleted'
            ? 'deleted'
            : ($post->status === 'hidden'
                ? 'hidden'
                : match ($latestVersion?->review_status ?? $post->status) {
                    'pending', 'pending_review' => 'pending_review',
                    'rejected' => 'rejected',
                    default => 'published',
                });

        return [
            'id' => $post->public_id,
            'author' => $author,
            'title' => $post->title,
            'content' => $post->content,
            'category_code' => $post->category_code,
            'category_label' => CommunityPost::CATEGORIES[$post->category_code] ?? '经验分享',
            'location_name' => $post->location_name,
            'status' => $post->trashed() ? 'deleted' : $post->status,
            'audit_status' => $auditStatus,
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
            'review_reason_code' => $latestVersion?->review_reason_code ?? $post->review_reason_code,
            'review_note' => $latestVersion?->review_note ?? $post->review_note,
            'reviewed_at' => $latestVersion?->reviewed_at,
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
