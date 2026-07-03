<?php

namespace App\Services;

use App\Models\ClearedHistory;
use App\Repositories\ConversationRepositoryInterface;
use App\Repositories\PollRepositoryInterface;
use App\Repositories\NoteRepositoryInterface;
use App\Repositories\MessageRepositoryInterface;

class ConversationService
{
    protected $conversationRepository;
    protected $pollRepository;
    protected $noteRepository;
    protected $messageRepository;

    public function __construct(
        ConversationRepositoryInterface $conversationRepository,
        PollRepositoryInterface $pollRepository,
        NoteRepositoryInterface $noteRepository,
        MessageRepositoryInterface $messageRepository
    ) {
        $this->conversationRepository = $conversationRepository;
        $this->pollRepository         = $pollRepository;
        $this->noteRepository         = $noteRepository;
        $this->messageRepository      = $messageRepository;
    }

    public function getUserConversations($userId)
    {
        return $this->conversationRepository->getUserConversations($userId);
    }

    public function accessDirectChat($userId, $otherUserId)
    {
        $conversation = $this->conversationRepository->findDirectChat($userId, $otherUserId);
        if ($conversation) {
            return $conversation;
        }
        return $this->conversationRepository->createDirectChat($userId, $otherUserId);
    }

    public function createGroupChat($name, $userIds, $creatorId)
    {
        if (count($userIds) < 1) {
            throw new \Exception('Cần ít nhất 2 người để tạo nhóm');
        }
        $userIds[] = $creatorId;
        return $this->conversationRepository->createGroupChat($name, $userIds, $creatorId);
    }

    public function updateConversationName($id, $name)
    {
        $this->conversationRepository->update($id, ['chat_name' => $name]);
        return $this->conversationRepository->findById($id);
    }

    public function getParticipants($conversationId)
    {
        $conversation = $this->conversationRepository->findById($conversationId);
        return $conversation ? $conversation->users : collect([]);
    }

    public function isAdmin($conversationId, $userId)
    {
        return $this->conversationRepository->isAdmin($conversationId, $userId);
    }

    public function addMember($conversationId, $userId)
    {
        if (!$this->conversationRepository->isMember($conversationId, $userId)) {
            $this->conversationRepository->addMember($conversationId, $userId);
        }
        return true;
    }

    public function removeMember($conversationId, $userId)
    {
        return $this->conversationRepository->removeMember($conversationId, $userId);
    }

    // Xóa lịch sử chat của một user
    public function clearHistory($conversationId, $userId)
    {
        ClearedHistory::updateOrCreate(
            ['conversation_id' => $conversationId, 'user_id' => $userId],
            ['cleared_at' => now()]
        );
        return true;
    }

    // Rời nhóm – có admin transfer logic
    public function leaveGroup($conversationId, $userId)
    {
        $conversation = $this->conversationRepository->findById($conversationId);
        if (!$conversation) throw new \Exception('Conversation không tồn tại');
        if (!$conversation->is_group_chat) throw new \Exception('Đây không phải nhóm chat');

        $members = $conversation->users;
        if ($members->count() <= 1) {
            // Chỉ còn mình, không xóa nhóm để giữ lịch sử, nhưng rời nhóm
            $this->conversationRepository->removeMember($conversationId, $userId);
            return;
        }

        $isAdmin = $this->conversationRepository->isAdmin($conversationId, $userId);
        $this->conversationRepository->removeMember($conversationId, $userId);

        // Nếu là admin, chuyển quyền cho thành viên kế tiếp
        if ($isAdmin) {
            $nextMember = $conversation->users->where('id', '!=', $userId)->first();
            if ($nextMember) {
                $conversation->users()->updateExistingPivot($nextMember->id, ['is_admin' => true]);
            }
        }

        // Không giải tán nhóm kể cả khi còn < 2 người để giữ lịch sử chat
    }

    public function grantAdmin($conversationId, $adminId, $targetUserId)
    {
        $conversation = $this->conversationRepository->findById($conversationId);
        if (!$conversation) throw new \Exception('Conversation không tồn tại');

        // Only the creator can grant/revoke admin rights
        if ((int)$conversation->creator_id !== (int)$adminId) {
            throw new \Exception('Chỉ trưởng nhóm (ADMIN) mới có quyền cấp/gỡ quyền quản trị');
        }

        // Toggle admin status
        $pivot = $conversation->users()->where('users.id', $targetUserId)->first()?->pivot;
        $newAdminState = !$pivot?->is_admin;

        $conversation->users()->updateExistingPivot($targetUserId, ['is_admin' => $newAdminState]);
        return $newAdminState;
    }

    public function markAsRead($conversationId, $userId)
    {
        $conversation = $this->conversationRepository->findById($conversationId);
        if (!$conversation) return false;

        $unreadMessages = $conversation->messages()
            ->where('sender_id', '!=', $userId)
            ->whereDoesntHave('readBy', fn($q) => $q->where('user_id', $userId))
            ->get();

        foreach ($unreadMessages as $message) {
            $message->readBy()->syncWithoutDetaching([$userId]);
        }

        // Broadcast messages.read event to notify other users
        broadcast(new \App\Events\MessagesRead($conversationId, $userId));

        return true;
    }

    // --- Polls ---
    public function getPolls($conversationId)
    {
        return $this->pollRepository->getByConversation($conversationId);
    }

    public function createPoll($conversationId, $question, $options, $creatorId)
    {
        return $this->pollRepository->create([
            'conversation_id' => $conversationId,
            'question'        => $question,
            'created_by'      => $creatorId,
            'is_active'       => true,
        ], $options);
    }

    public function votePoll($pollId, $optionId, $userId)
    {
        $poll = $this->pollRepository->findById($pollId);
        if (!$poll) throw new \Exception('Poll không tồn tại');

        // Xóa vote cũ của user trong poll này
        $optionIds = $poll->options->pluck('id')->toArray();
        \DB::table('poll_votes')
            ->whereIn('poll_option_id', $optionIds)
            ->where('user_id', $userId)
            ->delete();

        return $this->pollRepository->vote($optionId, $userId);
    }

    // --- Notes ---
    public function getNotes($conversationId)
    {
        return $this->noteRepository->getByConversation($conversationId);
    }

    public function createNote($conversationId, $content, $creatorId)
    {
        return $this->noteRepository->create([
            'conversation_id' => $conversationId,
            'content'         => $content,
            'created_by'      => $creatorId,
        ]);
    }

    public function updateNote($noteId, $content)
    {
        return $this->noteRepository->update($noteId, ['content' => $content]);
    }

    public function deleteNote($noteId)
    {
        return $this->noteRepository->delete($noteId);
    }
}
