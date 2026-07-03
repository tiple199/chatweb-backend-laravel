<?php

namespace App\Repositories;

use App\Models\Friend;

interface FriendRepositoryInterface
{
    public function findRequest(int $requesterId, int $recipientId): ?Friend;
    public function findById(int $id): ?Friend;
    public function getPendingRequests(int $userId): array;
    public function getFriendshipStatus(int $userId, int $targetUserId): string;
    public function create(int $requesterId, int $recipientId): Friend;
    public function deleteRequest(int $requesterId, int $recipientId): bool;
    public function deleteFriendship(int $userId, int $friendId): bool;
    public function getFriends(int $userId): array;
}
