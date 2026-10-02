<?php

use App\Models\User;
use App\Modules\Chat\Models\ChatConversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 私聊会话频道：仅会话双方可订阅
Broadcast::channel('chat.conversation.{publicId}', function (User $user, string $publicId) {
    $conv = ChatConversation::where('public_id', $publicId)->first();
    if (! $conv) {
        return false;
    }

    return $conv->user_a_id === $user->id || $conv->user_b_id === $user->id;
});
