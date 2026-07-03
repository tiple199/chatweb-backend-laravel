<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationRepository implements ConversationRepositoryInterface
{
    public function findById(int $id): ?Conversation
    {
        return Conversation::with(['users', 'latestMessage.sender'])->find($id);
    }

    public function getUserConversations(int $userId): array
    {
        return Conversation::whereHas('users', function ($q) use ($userId) {
            $q->where('users.id', $userId);
        })
        ->with(['users', 'latestMessage.sender'])
        ->orderBy('updated_at', 'desc')
        ->get()
        ->all();
    }

    public function findDirectChat(int $userId, int $otherUserId): ?Conversation
    {
        return Conversation::where('is_group_chat', false)
            ->whereHas('users', function ($q) use ($userId) {
                $q->where('users.id', $userId);
            })
            ->whereHas('users', function ($q) use ($otherUserId) {
                $q->where('users.id', $otherUserId);
            })
            ->first();
    }

    public function createDirectChat(int $userId, int $otherUserId): Conversation
    {
        return DB::transaction(function () use ($userId, $otherUserId) {
            $conversation = Conversation::create([
                'chat_name' => 'Direct Chat',
                'is_group_chat' => false,
            ]);

            $conversation->users()->attach([
                $userId => ['is_admin' => true],
                $otherUserId => ['is_admin' => true],
            ]);

            return $conversation->load('users');
        });
    }

    public function createGroupChat(string $chatName, array $userIds, int $creatorId): Conversation
    {
        return DB::transaction(function () use ($chatName, $userIds, $creatorId) {
            $conversation = Conversation::create([
                'chat_name' => $chatName,
                'is_group_chat' => true,
                'creator_id' => $creatorId,
            ]);

            // Ensure unique members list
            $members = array_unique(array_merge($userIds, [$creatorId]));

            // Attach members, making creator the admin
            $attachData = [];
            foreach ($members as $uId) {
                $attachData[$uId] = ['is_admin' => $uId === $creatorId];
            }

            $conversation->users()->attach($attachData);

            return $conversation->load('users');
        });
    }

    public function update(int $id, array $data): bool
    {
        $conversation = Conversation::find($id);
        if ($conversation) {
            return $conversation->update($data);
        }
        return false;
    }

    public function addMember(int $conversationId, int $userId, bool $isAdmin = false): bool
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) return false;

        if (!$conversation->users()->where('users.id', $userId)->exists()) {
            $conversation->users()->attach($userId, ['is_admin' => $isAdmin]);
            return true;
        }

        return false;
    }

    public function removeMember(int $conversationId, int $userId): bool
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) return false;

        if ($conversation->users()->where('users.id', $userId)->exists()) {
            $conversation->users()->detach($userId);
            return true;
        }

        return false;
    }

    public function isMember(int $conversationId, int $userId): bool
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) return false;

        return $conversation->users()->where('users.id', $userId)->exists();
    }

    public function isAdmin(int $conversationId, int $userId): bool
    {
        $conversation = Conversation::find($conversationId);
        if (!$conversation) return false;

        return $conversation->users()
            ->where('users.id', $userId)
            ->where('conversation_user.is_admin', true)
            ->exists();
    }
}
