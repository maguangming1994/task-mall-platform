<?php

namespace App\Modules\Chat\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\SecondHand\Models\SecondHandItem;
use App\Modules\SecondHand\Models\SecondHandOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $convs = ChatConversation::query()
            ->where(fn ($q) => $q->where('user_a_id', $userId)->orWhere('user_b_id', $userId))
            ->orderByDesc('last_message_at')
            ->latest('id')
            ->get();

        $items = $convs->map(function (ChatConversation $c) use ($userId) {
            $other = $c->user_a_id === $userId ? $c->userB : $c->userA;
            $unread = $c->user_a_id === $userId ? $c->unread_a : $c->unread_b;

            return [
                'id' => $c->public_id,
                'other_user' => $this->presentUser($other),
                'last_message' => $c->last_message,
                'last_message_type' => $c->last_message_type,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'unread' => (int) $unread,
                'item' => $c->item ? [
                    'id' => $c->item->public_id,
                    'title' => $c->item->title,
                    'cover' => $c->item->cover_file_public_id,
                    'price' => round($c->item->price / 100, 2),
                ] : null,
            ];
        });

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string'],
            'item_id' => ['nullable', 'string'],
            'order_id' => ['nullable', 'string'],
        ]);
        $me = $request->user();
        $other = User::where('public_id', $data['user_id'])->firstOrFail();
        abort_unless($other->id !== $me->id, 400, '不能与自己发起会话');

        $itemId = null;
        if (! empty($data['item_id'])) {
            $itemId = SecondHandItem::where('public_id', $data['item_id'])->value('id');
        }
        $orderId = null;
        if (! empty($data['order_id'])) {
            $orderId = SecondHandOrder::where('public_id', $data['order_id'])->value('id');
        }

        $a = min($me->id, $other->id);
        $b = max($me->id, $other->id);

        $conv = ChatConversation::firstOrCreate(
            ['user_a_id' => $a, 'user_b_id' => $b, 'item_id' => $itemId],
            ['order_id' => $orderId, 'last_message_at' => now()],
        );

        return ApiResponse::success(['id' => $conv->public_id]);
    }

    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless($conversation->user_a_id === $userId || $conversation->user_b_id === $userId, 404);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $data['page'] ?? 1;
        $size = $data['page_size'] ?? 50;

        $query = ChatMessage::where('conversation_id', $conversation->id);
        $total = $query->count();
        $messages = $query->forPage($page, $size)->orderByDesc('id')->get()->reverse()->values();

        // 标记对方消息为已读
        $this->markRead($conversation, $userId);

        return ApiResponse::success([
            'items' => $messages->map(fn (ChatMessage $m) => $this->presentMessage($m, $userId)),
            'pagination' => ['page' => $page, 'page_size' => $size, 'total' => $total],
            'conversation' => $this->presentConversation($conversation, $userId),
        ]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless($conversation->user_a_id === $userId || $conversation->user_b_id === $userId, 404);

        $data = $request->validate([
            'type' => ['nullable', 'in:text,image'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $message = DB::transaction(function () use ($conversation, $userId, $data) {
            $msg = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $userId,
                'type' => $data['type'] ?? 'text',
                'content' => $data['content'],
            ]);

            $isA = $conversation->user_a_id === $userId;
            $conversation->update([
                'last_message_id' => $msg->id,
                'last_message' => $msg->type === 'image' ? '[图片]' : $msg->content,
                'last_message_type' => $msg->type,
                'last_message_at' => now(),
                'unread_a' => $isA ? $conversation->unread_a : $conversation->unread_a + 1,
                'unread_b' => $isA ? $conversation->unread_b + 1 : $conversation->unread_b,
            ]);

            return $msg;
        });

        return ApiResponse::success($this->presentMessage($message, $userId), 201);
    }

    public function read(Request $request, ChatConversation $conversation): JsonResponse
    {
        $userId = $request->user()->id;
        abort_unless($conversation->user_a_id === $userId || $conversation->user_b_id === $userId, 404);
        $this->markRead($conversation, $userId);

        return ApiResponse::success(['id' => $conversation->public_id, 'unread' => 0]);
    }

    private function markRead(ChatConversation $conversation, int $userId): void
    {
        $isA = $conversation->user_a_id === $userId;
        $field = $isA ? 'unread_a' : 'unread_b';
        if ($conversation->$field > 0) {
            $conversation->update([$field => 0]);
        }
    }

    private function presentMessage(ChatMessage $m, int $viewerId): array
    {
        return [
            'id' => $m->public_id,
            'sender_id' => $m->sender->public_id,
            'mine' => $m->sender_id === $viewerId,
            'type' => $m->type,
            'content' => $m->content,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function presentConversation(ChatConversation $c, int $userId): array
    {
        $other = $c->user_a_id === $userId ? $c->userB : $c->userA;

        return [
            'id' => $c->public_id,
            'other_user' => $this->presentUser($other),
            'item' => $c->item ? ['id' => $c->item->public_id, 'title' => $c->item->title, 'cover' => $c->item->cover_file_public_id, 'price' => round($c->item->price / 100, 2)] : null,
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
