<?php

namespace App\Repositories;

use App\Models\Message;

interface MessageRepositoryInterface
{
    public function findById(int $id): ?Message;
    public function create(array $data): Message;
    public function getHistory(int $conversationId, int $page, int $limit): array;
    public function search(int $conversationId, string $keyword, ?string $messageType = null): array;
    public function markAsRead(int $conversationId, int $userId): bool;
    public function update(int $messageId, array $data): bool;
}
