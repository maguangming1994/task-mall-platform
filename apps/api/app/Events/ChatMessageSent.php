<?php

namespace App\Events;

use App\Modules\Chat\Models\ChatConversation;
use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChatMessage $message,
        public readonly ChatConversation $conversation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.conversation.'.$this->conversation->public_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $m = $this->message;
        $c = $this->conversation;

        return [
            'message' => [
                'id' => $m->public_id,
                'conversation_id' => $c->public_id,
                'sender_id' => $m->sender?->public_id,
                'type' => $m->type,
                'content' => $m->content,
                'created_at' => $m->created_at?->toIso8601String(),
            ],
            'conversation' => [
                'id' => $c->public_id,
                'last_message' => $c->last_message,
                'last_message_type' => $c->last_message_type,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ],
        ];
    }
}
