<?php

namespace App\Modules\Community\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Community\Models\UserBlock;
use App\Modules\Community\Models\UserFollow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunitySocialController extends Controller
{
    public function follow(Request $request, string $userId): JsonResponse
    {
        $target = $this->resolveUser($userId);
        abort_if($target->id === $request->user()->id, 422, '不能关注自己。');
        abort_if(UserBlock::existsBetween($request->user(), $target->id), 403, '拉黑状态下不能关注对方。');

        UserFollow::query()->firstOrCreate([
            'follower_id' => $request->user()->id,
            'following_id' => $target->id,
        ]);

        return ApiResponse::success($this->userState($request->user(), $target));
    }

    public function unfollow(Request $request, string $userId): JsonResponse
    {
        $target = $this->resolveUser($userId);
        UserFollow::query()
            ->where('follower_id', $request->user()->id)
            ->where('following_id', $target->id)
            ->delete();

        return ApiResponse::success($this->userState($request->user(), $target));
    }

    public function stats(Request $request): JsonResponse
    {
        return ApiResponse::success($this->counts($request->user()->id, $request->user()->id));
    }

    private function resolveUser(string $publicId): User
    {
        $user = User::query()->withoutGlobalScope('admin_campus')->where('public_id', $publicId)->where('status', 'active')->first();
        abort_if($user === null, 404);

        return $user;
    }

    private function userState(User $viewer, User $target): array
    {
        return [
            'user_id' => $target->public_id,
            'is_followed' => UserFollow::query()
                ->where('follower_id', $viewer->id)->where('following_id', $target->id)->exists(),
        ] + $this->counts($target->id, $viewer->id);
    }

    /**
     * @return array{follower_count:int,following_count:int,is_followed:bool}
     */
    private function counts(int $targetId, int $viewerId): array
    {
        return [
            'follower_count' => UserFollow::query()->where('following_id', $targetId)->count(),
            'following_count' => UserFollow::query()->where('follower_id', $targetId)->count(),
            'is_followed' => UserFollow::query()
                ->where('follower_id', $viewerId)->where('following_id', $targetId)->exists(),
        ];
    }
}
