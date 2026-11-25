<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Community\Models\CommunityPost;
use App\Modules\Community\Models\CommunityPostFavorite;
use App\Modules\Community\Models\CommunityPostLike;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunityEngagementController extends Controller
{
    public function like(Request $request, CommunityPost $post, NotificationService $notifications): JsonResponse
    {
        abort_unless($post->status === 'published' && $post->isVisibleTo($request->user()), 404);
        $created = DB::transaction(function () use ($request, $post): bool {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            $like = CommunityPostLike::query()->firstOrCreate([
                'community_post_id' => $locked->id,
                'user_id' => $request->user()->id,
            ]);
            if ($like->wasRecentlyCreated) {
                $locked->increment('like_count');
            }

            return $like->wasRecentlyCreated;
        }, 3);

        $actor = $request->user();
        if ($created && $post->user_id !== $actor->id) {
            $notifications->create(
                User::query()->findOrFail($post->user_id),
                'community',
                'community_post_liked',
                '帖子收到新点赞',
                ($actor->nickname ?: '一位同学').'点赞了你的帖子《'.$post->title.'》。',
                'community-post-like:'.$post->public_id.':'.$actor->public_id,
                false,
                ['in_app'],
                ['post_id' => $post->public_id],
            );
        }

        return ApiResponse::success($this->state($request, $post->fresh()));
    }

    public function unlike(Request $request, CommunityPost $post): JsonResponse
    {
        abort_unless($post->status === 'published' && $post->isVisibleTo($request->user()), 404);
        DB::transaction(function () use ($request, $post): void {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            $deleted = CommunityPostLike::query()
                ->where('community_post_id', $locked->id)
                ->where('user_id', $request->user()->id)
                ->delete();
            if ($deleted > 0) {
                $locked->update(['like_count' => max(0, $locked->like_count - 1)]);
            }
        }, 3);

        return ApiResponse::success($this->state($request, $post->fresh()));
    }

    public function favorite(Request $request, CommunityPost $post): JsonResponse
    {
        abort_unless($post->status === 'published' && $post->isVisibleTo($request->user()), 404);
        DB::transaction(function () use ($request, $post): void {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            $favorite = CommunityPostFavorite::query()->firstOrCreate([
                'community_post_id' => $locked->id,
                'user_id' => $request->user()->id,
            ]);
            if ($favorite->wasRecentlyCreated) {
                $locked->increment('favorite_count');
            }
        }, 3);

        return ApiResponse::success($this->favoriteState($request, $post->fresh()));
    }

    public function unfavorite(Request $request, CommunityPost $post): JsonResponse
    {
        abort_unless($post->status === 'published' && $post->isVisibleTo($request->user()), 404);
        DB::transaction(function () use ($request, $post): void {
            $locked = CommunityPost::query()->lockForUpdate()->findOrFail($post->id);
            $deleted = CommunityPostFavorite::query()
                ->where('community_post_id', $locked->id)
                ->where('user_id', $request->user()->id)
                ->delete();
            if ($deleted > 0) {
                $locked->update(['favorite_count' => max(0, (int) $locked->favorite_count - 1)]);
            }
        }, 3);

        return ApiResponse::success($this->favoriteState($request, $post->fresh()));
    }

    public function state(Request $request, CommunityPost $post): array
    {
        return [
            'post_id' => $post->public_id,
            'like_count' => $post->like_count,
            'liked_by_me' => CommunityPostLike::query()->where('community_post_id', $post->id)->where('user_id', $request->user()->id)->exists(),
        ];
    }

    private function favoriteState(Request $request, CommunityPost $post): array
    {
        return [
            'post_id' => $post->public_id,
            'favorite_count' => (int) $post->favorite_count,
            'favorited_by_me' => CommunityPostFavorite::query()->where('community_post_id', $post->id)->where('user_id', $request->user()->id)->exists(),
        ];
    }
}
