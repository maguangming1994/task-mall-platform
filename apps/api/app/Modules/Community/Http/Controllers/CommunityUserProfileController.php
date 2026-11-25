<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Community\Models\CommunityActivity;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\UserBlock;
use App\Modules\Community\Models\UserFollow;
use App\Modules\Community\Models\UserProfileVisit;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandRating;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskClaim;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskVersion;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class CommunityUserProfileController extends Controller
{
    private const CONTENT_TYPES = ['posts', 'second_hand', 'activities', 'tasks', 'claims', 'impressions'];

    private const CONDITIONS = [
        'new' => '全新',
        'like_new' => '几乎全新',
        'very_good' => '轻微使用',
        'good' => '正常使用',
        'fair' => '明显使用',
    ];

    public function show(Request $request, string $userId): JsonResponse
    {
        $target = $this->resolveUser($userId);
        $viewer = $request->user();
        $isSelf = $viewer?->id === $target->id;
        $isBlocked = $this->isBlockedBy($viewer, $target);
        $blocksMe = $this->blocksViewer($viewer, $target);
        abort_if($blocksMe && ! $isBlocked, 404);
        $hasBlock = $isBlocked || $blocksMe;

        if ($viewer !== null && ! $isSelf && ! $hasBlock) {
            $this->recordVisit($target, $viewer);
        }

        $canSeeFollowers = ! $hasBlock && ($isSelf || ($viewer !== null && UserFollow::query()
            ->where('follower_id', $viewer->id)->where('following_id', $target->id)->exists()));
        $ratingQuery = SecondHandRating::query()->where('ratee_user_id', $target->id);
        $ratingCount = $hasBlock ? 0 : (clone $ratingQuery)->count();

        return ApiResponse::success([
            'id' => $target->public_id,
            'uid' => $target->public_id,
            'nickname' => $target->nickname ?: '校园用户',
            'avatar_url' => $this->avatarUrl($target),
            'bio' => $target->bio ?: '',
            'school_name' => $target->campus?->name,
            'campus_name' => $target->campus?->name,
            'college' => $target->college,
            'grade_year' => $target->grade_year,
            'major' => $target->major,
            'verified' => UserIdentityVerification::query()->where('user_id', $target->id)->where('status', 'verified')->exists(),
            'is_self' => $isSelf,
            'is_followed' => ! $hasBlock && $viewer !== null && UserFollow::query()->where('follower_id', $viewer->id)->where('following_id', $target->id)->exists(),
            'is_blocked' => $isBlocked,
            'can_message' => $viewer !== null && ! $isSelf && ! $hasBlock,
            'follower_count' => UserFollow::query()->where('following_id', $target->id)->count(),
            'following_count' => UserFollow::query()->where('follower_id', $target->id)->count(),
            'visitor_count' => UserProfileVisit::query()->where('profile_user_id', $target->id)->count(),
            'recent_visitors' => $this->recentVisitors($target),
            'rating_average' => $ratingCount > 0 ? round((float) (clone $ratingQuery)->avg('score'), 1) : null,
            'rating_count' => $ratingCount,
            'content_counts' => $hasBlock ? $this->emptyCounts() : $this->contentCounts($target, $canSeeFollowers),
        ]);
    }

    public function content(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(self::CONTENT_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        $type = $data['type'] ?? 'posts';
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 10;
        $target = $this->resolveUser($userId);
        $viewer = $request->user();
        $isBlocked = $this->isBlockedBy($viewer, $target);
        abort_if($this->blocksViewer($viewer, $target) && ! $isBlocked, 404);

        if ($isBlocked) {
            return ApiResponse::success(['type' => $type, 'items' => [], 'pagination' => $this->pagination($page, $size, 0)]);
        }

        $canSeeFollowers = $viewer?->id === $target->id || ($viewer !== null && UserFollow::query()
            ->where('follower_id', $viewer->id)->where('following_id', $target->id)->exists());

        return match ($type) {
            'second_hand' => $this->secondHandContent($target, $page, $size),
            'activities' => $this->activitiesContent($target, $page, $size),
            'tasks' => $this->tasksContent($target, $page, $size),
            'claims' => $this->claimsContent($target, $page, $size),
            'impressions' => $this->impressionsContent($target, $page, $size),
            default => $this->postsContent($target, $canSeeFollowers, $page, $size),
        };
    }

    public function block(Request $request, string $userId): JsonResponse
    {
        $target = $this->resolveUser($userId);
        $viewer = $request->user();
        abort_if($target->id === $viewer->id, 422, '不能拉黑自己。');

        DB::transaction(function () use ($viewer, $target): void {
            UserBlock::query()->firstOrCreate(['blocker_user_id' => $viewer->id, 'blocked_user_id' => $target->id]);
            UserFollow::query()->where(function ($query) use ($viewer, $target): void {
                $query->where(fn ($q) => $q->where('follower_id', $viewer->id)->where('following_id', $target->id))
                    ->orWhere(fn ($q) => $q->where('follower_id', $target->id)->where('following_id', $viewer->id));
            })->delete();
        });

        return ApiResponse::success(['user_id' => $target->public_id, 'is_blocked' => true, 'is_followed' => false]);
    }

    public function unblock(Request $request, string $userId): JsonResponse
    {
        $target = $this->resolveUser($userId);
        UserBlock::query()->where('blocker_user_id', $request->user()->id)->where('blocked_user_id', $target->id)->delete();

        return ApiResponse::success(['user_id' => $target->public_id, 'is_blocked' => false, 'is_followed' => false]);
    }

    private function resolveUser(string $publicId): User
    {
        $user = User::query()->withoutGlobalScope('admin_campus')->with('campus')
            ->where('public_id', $publicId)->where('status', 'active')->first();
        abort_if($user === null, 404);

        return $user;
    }

    private function isBlockedBy(?User $viewer, User $target): bool
    {
        return $viewer !== null && UserBlock::query()->where('blocker_user_id', $viewer->id)->where('blocked_user_id', $target->id)->exists();
    }

    private function blocksViewer(?User $viewer, User $target): bool
    {
        return $viewer !== null && UserBlock::query()->where('blocker_user_id', $target->id)->where('blocked_user_id', $viewer->id)->exists();
    }

    private function recordVisit(User $target, User $viewer): void
    {
        $visit = UserProfileVisit::query()->firstOrNew(['profile_user_id' => $target->id, 'visitor_user_id' => $viewer->id]);
        $visit->visit_count = ($visit->exists ? $visit->visit_count : 0) + 1;
        $visit->last_visited_at = now();
        $visit->save();
    }

    private function recentVisitors(User $target): array
    {
        $visits = UserProfileVisit::query()->where('profile_user_id', $target->id)
            ->orderByDesc('last_visited_at')->limit(6)->get();
        $users = User::query()->withoutGlobalScope('admin_campus')->whereIn('id', $visits->pluck('visitor_user_id'))->get()->keyBy('id');

        return $visits->map(function (UserProfileVisit $visit) use ($users): ?array {
            $user = $users->get($visit->visitor_user_id);
            if ($user === null || $user->status !== 'active') {
                return null;
            }

            return [
                'id' => $user->public_id,
                'nickname' => $user->nickname ?: '校园用户',
                'avatar_url' => $this->avatarUrl($user),
                'visited_at' => $visit->last_visited_at?->toIso8601String(),
            ];
        })->filter()->values()->all();
    }

    private function avatarUrl(User $user): ?string
    {
        if ($user->avatar_file_id === null) {
            return null;
        }
        $avatar = UploadedFile::query()->find($user->avatar_file_id);

        return $avatar === null ? null : rtrim((string) config('app.url'), '/').route('user-avatars.show', ['file' => $avatar], false);
    }

    private function emptyCounts(): array
    {
        return array_fill_keys(self::CONTENT_TYPES, 0);
    }

    private function contentCounts(User $target, bool $canSeeFollowers): array
    {
        return [
            'posts' => $this->postsQuery($target, $canSeeFollowers)->count(),
            'second_hand' => $this->secondHandQuery($target)->count(),
            'activities' => $this->activitiesQuery($target)->count(),
            'tasks' => $this->tasksQuery($target)->count(),
            'claims' => $this->claimsQuery($target)->count(),
            'impressions' => $this->impressionsQuery($target)->count(),
        ];
    }

    private function postsQuery(User $target, bool $canSeeFollowers): Builder
    {
        return CommunityPost::query()->withoutGlobalScope('campus_context')
            ->where('user_id', $target->id)->where('status', 'published')
            ->where(function ($query) use ($canSeeFollowers): void {
                $query->where('visibility', 'public');
                if ($canSeeFollowers) {
                    $query->orWhere('visibility', 'followers');
                }
            });
    }

    private function secondHandQuery(User $target): Builder
    {
        return SecondHandItem::query()->withoutGlobalScope('campus_context')
            ->where('seller_user_id', $target->id)->where('status', 'on_sale')->where('audit_status', 'approved');
    }

    private function activitiesQuery(User $target): Builder
    {
        return CommunityActivity::query()->withoutGlobalScope('campus_context')
            ->where('creator_user_id', $target->id)->where('status', 'approved');
    }

    private function tasksQuery(User $target): Builder
    {
        $adminIds = TaskPublisher::query()->where('linked_user_id', $target->id)->pluck('admin_user_id');

        return Task::query()->withoutGlobalScope('campus_context')
            ->whereIn('created_by_admin_id', $adminIds)->where('status', 'published')->whereNotNull('published_version_no');
    }

    private function claimsQuery(User $target): Builder
    {
        return TaskClaim::query()->where('user_id', $target->id)->where('status', 'approved')->whereNotNull('approved_at');
    }

    private function impressionsQuery(User $target): Builder
    {
        return SecondHandRating::query()->where('ratee_user_id', $target->id);
    }

    private function postsContent(User $target, bool $canSeeFollowers, int $page, int $size): JsonResponse
    {
        $query = $this->postsQuery($target, $canSeeFollowers);
        $total = $query->count();
        $items = $query->orderByDesc('published_at')->orderByDesc('id')->forPage($page, $size)->get()->map(fn (CommunityPost $post) => [
            'id' => $post->public_id,
            'title' => $post->title,
            'content' => $post->content,
            'category_code' => $post->category_code,
            'category_label' => CommunityPost::CATEGORIES[$post->category_code] ?? '校园动态',
            'location_name' => $post->location_name,
            'like_count' => (int) $post->like_count,
            'comment_count' => (int) $post->comment_count,
            'attachments' => $this->postAttachments($post),
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ])->values();

        return ApiResponse::success(['type' => 'posts', 'items' => $items, 'pagination' => $this->pagination($page, $size, $total)]);
    }

    private function secondHandContent(User $target, int $page, int $size): JsonResponse
    {
        $query = $this->secondHandQuery($target);
        $total = $query->count();
        $items = $query->latest('id')->forPage($page, $size)->with('category')->get()->map(fn (SecondHandItem $item) => [
            'id' => $item->public_id,
            'title' => $item->title,
            'description' => $item->description,
            'price' => round($item->price / 100, 2),
            'condition' => $item->condition,
            'condition_label' => self::CONDITIONS[$item->condition] ?? $item->condition,
            'cover' => $item->cover_file_public_id,
            'location' => $item->location,
            'category' => $item->category ? ['id' => $item->category->public_id, 'name' => $item->category->name] : null,
            'created_at' => $item->created_at?->toIso8601String(),
        ])->values();

        return ApiResponse::success(['type' => 'second_hand', 'items' => $items, 'pagination' => $this->pagination($page, $size, $total)]);
    }

    private function activitiesContent(User $target, int $page, int $size): JsonResponse
    {
        $query = $this->activitiesQuery($target);
        $total = $query->count();
        $items = $query->orderByDesc('starts_at')->orderByDesc('id')->forPage($page, $size)->get()->map(fn (CommunityActivity $activity) => [
            'id' => $activity->public_id,
            'title' => $activity->title,
            'details' => $activity->details,
            'category_code' => $activity->category_code,
            'category_label' => CommunityActivity::CATEGORIES[$activity->category_code] ?? '攒局',
            'location' => $activity->location,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'participant_count' => (int) $activity->participant_count,
            'participant_limit' => (int) $activity->participant_limit,
            'created_at' => $activity->created_at?->toIso8601String(),
        ])->values();

        return ApiResponse::success(['type' => 'activities', 'items' => $items, 'pagination' => $this->pagination($page, $size, $total)]);
    }

    private function tasksContent(User $target, int $page, int $size): JsonResponse
    {
        $query = $this->tasksQuery($target);
        $total = $query->count();
        $items = $query->orderByDesc('published_at')->orderByDesc('id')->forPage($page, $size)->get()->map(function (Task $task): ?array {
            $version = TaskVersion::query()->where('task_id', $task->id)->where('version_no', $task->published_version_no)->first();
            if ($version === null) {
                return null;
            }
            $content = $version->content;

            return [
                'id' => $task->public_id,
                'title' => (string) ($content['title'] ?? '校园任务'),
                'description' => (string) ($content['description_text'] ?? strip_tags((string) ($content['description'] ?? ''))),
                'task_type' => $content['task_type'] ?? null,
                'published_at' => $task->published_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
            ];
        })->filter()->values();

        return ApiResponse::success(['type' => 'tasks', 'items' => $items, 'pagination' => $this->pagination($page, $size, $total)]);
    }

    private function claimsContent(User $target, int $page, int $size): JsonResponse
    {
        $query = $this->claimsQuery($target);
        $total = $query->count();
        $claims = $query->orderByDesc('approved_at')->orderByDesc('id')->forPage($page, $size)->get();
        $tasks = Task::query()->withoutGlobalScope('campus_context')->whereIn('id', $claims->pluck('task_id'))->get()->keyBy('id');
        $items = $claims->map(fn (TaskClaim $claim) => [
            'id' => $claim->public_id,
            'task_id' => $tasks->get($claim->task_id)?->public_id,
            'title' => (string) ($claim->task_snapshot['title'] ?? '校园任务'),
            'description' => (string) ($claim->task_snapshot['description_text'] ?? ''),
            'status' => 'approved',
            'approved_at' => $claim->approved_at?->toIso8601String(),
        ])->values();

        return ApiResponse::success(['type' => 'claims', 'items' => $items, 'pagination' => $this->pagination($page, $size, $total)]);
    }

    private function impressionsContent(User $target, int $page, int $size): JsonResponse
    {
        $query = $this->impressionsQuery($target);
        $total = $query->count();
        $ratings = $query->latest('id')->forPage($page, $size)->with('rater')->get();
        $items = $ratings->map(fn (SecondHandRating $rating) => [
            'id' => $rating->public_id,
            'score' => (int) $rating->score,
            'content' => $rating->content,
            'images' => $rating->images ?? [],
            'reply_content' => $rating->reply_content,
            'rater' => $rating->rater ? [
                'id' => $rating->rater->public_id,
                'nickname' => $rating->rater->nickname ?: '校园用户',
                'avatar_url' => $this->avatarUrl($rating->rater),
            ] : null,
            'created_at' => $rating->created_at?->toIso8601String(),
        ])->values();

        return ApiResponse::success(['type' => 'impressions', 'items' => $items, 'pagination' => $this->pagination($page, $size, $total)]);
    }

    private function postAttachments(CommunityPost $post): array
    {
        if ($post->published_version_no === null) {
            return [];
        }

        return DB::table('community_attachments as attachment')
            ->join('community_post_versions as version', 'version.id', '=', 'attachment.community_post_version_id')
            ->join('uploaded_files as file', 'file.id', '=', 'attachment.uploaded_file_id')
            ->where('version.community_post_id', $post->id)->where('version.version_no', $post->published_version_no)
            ->where('file.status', 'clean')->orderBy('attachment.sort_order')
            ->get(['file.public_id as file_id', 'attachment.file_type as type'])
            ->map(fn ($attachment) => [
                'file_id' => $attachment->file_id,
                'type' => $attachment->type,
                'url' => $post->visibility === 'followers'
                    ? URL::temporarySignedRoute('community.media', now()->addHour(), ['file' => $attachment->file_id])
                    : "/community/media/{$attachment->file_id}",
            ])
            ->all();
    }

    private function pagination(int $page, int $size, int $total): array
    {
        return ['page' => $page, 'page_size' => $size, 'total' => $total, 'has_more' => $page * $size < $total];
    }
}
