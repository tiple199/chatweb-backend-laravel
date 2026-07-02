<?php

namespace App\Pipeline;

use App\Models\Conversation;

/**
 * Handler 1: Kiểm tra user có trong conversation không.
 */
class ConversationPermissionHandler
{
    public function handle(array $context, callable $next)
    {
        $conversationId = $context['conversationId'];
        $userId         = $context['userId'];

        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            throw new \Exception('Conversation không tồn tại', 404);
        }

        $isMember = $conversation->users()->where('users.id', $userId)->exists();

        if (!$isMember) {
            throw new \Exception('Bạn không có quyền gửi tin nhắn vào cuộc trò chuyện này', 403);
        }

        if ($conversation->is_group_chat && $conversation->users()->count() < 3) {
            throw new \Exception('Nhóm đã đóng do không đủ thành viên', 403);
        }

        return $next($context);
    }
}
