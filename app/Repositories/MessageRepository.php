<?php

namespace App\Repositories;

use App\Models\Message;
use Illuminate\Support\Facades\DB;

class MessageRepository implements MessageRepositoryInterface
{
    public function findById(int $id): ?Message
    {
        return Message::with(['sender', 'readBy'])->find($id);
    }

    public function create(array $data): Message
    {
        return Message::create($data);
    }

    public function getHistory(int $conversationId, int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;

        $messages = Message::where('conversation_id', $conversationId)
            ->with(['sender', 'readBy'])
            ->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $totalMessages = Message::where('conversation_id', $conversationId)->count();

        return [
            'messages' => $messages->reverse()->values()->all(),
            'currentPage' => $page,
            'totalPages' => (int) ceil($totalMessages / $limit),
            'totalMessages' => $totalMessages
        ];
    }

    public function search(int $conversationId, string $keyword, ?string $messageType = null): array
    {
        $query = Message::where('conversation_id', $conversationId)
            ->with(['sender', 'readBy']);

        if ($keyword) {
            $query->where('content', 'like', "%{$keyword}%");
        }

        if ($messageType) {
            $query->where('message_type', $messageType);
        }

        return $query->orderBy('created_at', 'desc')->get()->all();
    }

    public function markAsRead(int $conversationId, int $userId): bool
    {
        // Find messages in the conversation sent by others, and not already read by this user
        $unreadMessages = Message::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereDoesntHave('readBy', function ($q) use ($userId) {
                $q->where('users.id', $userId);
            })
            ->get();

        if ($unreadMessages->isEmpty()) {
            return false;
        }

        foreach ($unreadMessages as $message) {
            $message->readBy()->syncWithoutDetaching([$userId]);
        }

        return true;
    }

    public function update(int $messageId, array $data): bool
    {
        $message = Message::find($messageId);
        if ($message) {
            return $message->update($data);
        }
        return false;
    }
}
