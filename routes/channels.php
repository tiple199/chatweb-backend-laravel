<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{conversationId}', function ($user, $conversationId) {
    // Only allow if the user is part of the conversation
    $conversation = \App\Models\Conversation::find($conversationId);
    if ($conversation && $conversation->users->contains($user->id)) {
        return true;
    }
    return false;
});

Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('online', function ($user) {
    return [
        'id' => $user->id,
        'fullName' => $user->full_name,
        'avatar' => $user->avatar
    ];
});
