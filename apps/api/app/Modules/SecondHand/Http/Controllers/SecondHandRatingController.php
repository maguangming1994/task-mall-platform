<?php

namespace App\Modules\SecondHand\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\SecondHand\Models\SecondHandOrder;
use App\Modules\SecondHand\Models\SecondHandRating;
use App\Modules\SecondHand\Services\SecondHandNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SecondHandRatingController extends Controller
{
    public function __construct(private readonly SecondHandNotificationService $notify) {}

    public function store(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id || $order->seller_user_id === $user->id, 403);
        abort_unless($order->status === 'completed', 409, '订单完成后才能评价');

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['string', 'max:26'],
        ]);

        // 双方互评，每单每方只能评一次
        $existing = SecondHandRating::query()->where('order_id', $order->id)->where('rater_user_id', $user->id)->first();
        abort_if($existing !== null, 409, '已评价过该订单');

        $rateeId = $order->buyer_user_id === $user->id ? $order->seller_user_id : $order->buyer_user_id;

        $rating = DB::transaction(function () use ($order, $user, $rateeId, $data) {
            return SecondHandRating::create([
                'order_id' => $order->id,
                'item_id' => $order->item_id,
                'rater_user_id' => $user->id,
                'ratee_user_id' => $rateeId,
                'score' => $data['score'],
                'content' => $data['content'] ?? null,
                'images' => $data['images'] ?? [],
            ]);
        });
        $rating->loadMissing(['order', 'rater', 'ratee']);
        $this->notify->ratingReceived($rating);

        return ApiResponse::success($this->present($rating), 201);
    }

    public function index(Request $request, SecondHandOrder $order): JsonResponse
    {
        $user = $request->user();
        abort_unless($order->buyer_user_id === $user->id || $order->seller_user_id === $user->id, 403);

        $ratings = SecondHandRating::query()->where('order_id', $order->id)
            ->with(['rater', 'ratee'])->get();

        return ApiResponse::success(['items' => $ratings->map(fn (SecondHandRating $r) => $this->present($r))]);
    }

    public function reply(Request $request, SecondHandRating $rating): JsonResponse
    {
        $user = $request->user();
        abort_unless($rating->ratee_user_id === $user->id, 403);
        abort_unless($rating->reply_content === null, 409, '已回复过');

        $data = $request->validate([
            'reply_content' => ['required', 'string', 'max:500'],
        ]);

        $rating->update(['reply_content' => $data['reply_content'], 'replied_at' => now()]);

        return ApiResponse::success($this->present($rating));
    }

    public function userRatings(Request $request, string $user): JsonResponse
    {
        $user = User::query()->where('public_id', $user)->firstOrFail();
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
            'role' => ['nullable', Rule::in(['buyer', 'seller'])],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 20;

        $query = SecondHandRating::query()->where('ratee_user_id', $user->id)
            ->with(['rater', 'order'])
            ->latest('id');
        $total = $query->count();
        $items = $query->forPage($page, $size)->get();

        $avg = SecondHandRating::query()->where('ratee_user_id', $user->id)->avg('score');

        return ApiResponse::success([
            'items' => $items->map(fn (SecondHandRating $r) => $this->present($r)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
            'average_score' => $avg !== null ? round($avg, 1) : null,
            'total_count' => $total,
        ]);
    }

    private function present(SecondHandRating $rating): array
    {
        return [
            'id' => $rating->public_id,
            'order_id' => $rating->order?->public_id,
            'score' => (int) $rating->score,
            'content' => $rating->content,
            'images' => $rating->images ?? [],
            'reply_content' => $rating->reply_content,
            'replied_at' => $rating->replied_at?->toIso8601String(),
            'rater' => $this->presentUser($rating->rater),
            'ratee' => $this->presentUser($rating->ratee),
            'created_at' => $rating->created_at?->toIso8601String(),
        ];
    }

    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->public_id, 'nickname' => $user->nickname, 'avatar' => $user->avatar_file_id];
    }
}
