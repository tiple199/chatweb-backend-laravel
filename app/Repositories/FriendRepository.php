<?php

namespace App\Repositories;

use App\Models\Friend;
use App\Models\User;

class FriendRepository implements FriendRepositoryInterface
{
    public function findRequest(int $requesterId, int $recipientId): ?Friend
    {
        return Friend::where('requester_id', $requesterId)
            ->where('recipient_id', $recipientId)
            ->first();
    }

    public function findById(int $id): ?Friend
    {
        return Friend::find($id);
    }

    public function getPendingRequests(int $userId): array
    {
        return Friend::where('recipient_id', $userId)
            ->where('status', 'pending')
            ->with('requester')
            ->get()
            ->all();
    }

    public function getFriendshipStatus(int $userId, int $targetUserId): string
    {
        if ($userId === $targetUserId) {
            return 'friends';
        }

        $relation = Friend::where(function ($query) use ($userId, $targetUserId) {
            $query->where('requester_id', $userId)->where('recipient_id', $targetUserId);
        })->orWhere(function ($query) use ($userId, $targetUserId) {
            $query->where('requester_id', $targetUserId)->where('recipient_id', $userId);
        })->first();

        if (!$relation) return 'none';
        if ($relation->status === 'accepted') return 'friends';
        if ($relation->status === 'blocked') return 'blocked';
        if ($relation->status === 'pending') {
            return $relation->requester_id === $userId ? 'pending_sent' : 'pending_received';
        }

        return 'none';
    }

    public function create(int $requesterId, int $recipientId): Friend
    {
        return Friend::create([
            'requester_id' => $requesterId,
            'recipient_id' => $recipientId,
            'status' => 'pending'
        ]);
    }

    public function deleteRequest(int $requesterId, int $recipientId): bool
    {
        return Friend::where('requester_id', $requesterId)
            ->where('recipient_id', $recipientId)
            ->where('status', 'pending')
            ->delete() > 0;
    }

    public function deleteFriendship(int $userId, int $friendId): bool
    {
        return Friend::where(function ($query) use ($userId, $friendId) {
            $query->where('requester_id', $userId)
                  ->where('recipient_id', $friendId);
        })->orWhere(function ($query) use ($userId, $friendId) {
            $query->where('requester_id', $friendId)
                  ->where('recipient_id', $userId);
        })->where('status', 'accepted')
          ->delete() > 0;
    }

    public function getFriends(int $userId): array
    {
        $friendships = Friend::where(function ($query) use ($userId) {
            $query->where('requester_id', $userId)->orWhere('recipient_id', $userId);
        })->where('status', 'accepted')
          ->with('requester', 'recipient')
          ->get();

        $friends = [];
        foreach ($friendships as $f) {
            if ($f->requester_id === $userId) {
                $friends[] = $f->recipient;
            } else {
                $friends[] = $f->requester;
            }
        }
        return $friends;
    }
}
