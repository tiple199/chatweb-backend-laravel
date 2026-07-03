<?php

namespace App\Services;

use App\Models\ClearedHistory;
use App\Models\Message;
use App\Repositories\MessageRepositoryInterface;
use App\Repositories\ConversationRepositoryInterface;

class MessageService
{
    protected $messageRepository;
    protected $conversationRepository;
    protected $cloudinaryService;

    public function __construct(
        MessageRepositoryInterface $messageRepository,
        ConversationRepositoryInterface $conversationRepository,
        CloudinaryService $cloudinaryService
    ) {
        $this->messageRepository     = $messageRepository;
        $this->conversationRepository = $conversationRepository;
        $this->cloudinaryService     = $cloudinaryService;
    }

    /**
     * Lấy lịch sử tin nhắn, lọc theo cleared_at của user.
     * Sort: mới nhất → reverse để hiển thị cũ nhất trước.
     */
    public function getHistory($conversationId, $userId, $page = 1, $limit = 30)
    {
        // Tìm cleared_at của user này trong conversation
        $cleared = ClearedHistory::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        $query = Message::where('conversation_id', $conversationId)
            ->where('is_deleted_for_all', false);

        if ($cleared) {
            $query->where('created_at', '>', $cleared->cleared_at);
        }

        $total = $query->count();
        $totalPages = max(1, ceil($total / $limit));

        // Lấy page mới nhất, sau đó reverse để hiển thị ASC
        $messages = $query
            ->orderByDesc('created_at')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->with(['sender', 'readBy'])
            ->get()
            ->reverse()
            ->values()
            ->all();

        return [
            'messages'      => $messages,
            'currentPage'   => $page,
            'totalPages'    => $totalPages,
            'totalMessages' => $total,
        ];
    }

    public function searchMessages($keyword, $conversationId, $userId, $messageType = null)
    {
        $query = Message::where('is_deleted_for_all', false);

        if (!empty($keyword)) {
            $query->where('content', 'LIKE', "%{$keyword}%");
        }

        if ($messageType) {
            $query->where('message_type', $messageType);
        }

        if ($conversationId) {
            $query->where('conversation_id', $conversationId);
        } else {
            $query->whereHas('conversation.users', fn($q) => $q->where('users.id', $userId));
        }

        return $query->with(['sender', 'readBy'])->orderByDesc('created_at')->get()->all();
    }

    public function sendMessage($conversationId, $userId, $content, $messageType, $replyToMessageId, $file = null)
    {
        $fileUrl      = null;
        $fileProvider = null;
        $filePublicId = null;
        $fileName     = null;
        $fileSize     = null;
        $mimeType     = null;

        if ($file) {
            $fileName     = $file->getClientOriginalName();
            $fileSize     = $file->getSize();
            $mimeType     = $file->getMimeType();

            // Xác định loại message từ MIME
            if (str_starts_with($mimeType, 'image/')) {
                $messageType = 'image';
            } elseif (str_starts_with($mimeType, 'video/')) {
                $messageType = 'video';
            } else {
                $messageType = 'file';
            }

            $uploadResult = $this->cloudinaryService->uploadFile($file, 'attachments');
            $fileUrl      = $uploadResult['url']       ?? null;
            $filePublicId = $uploadResult['public_id'] ?? null;
            $fileProvider = 'cloudinary';

            if (!$fileUrl) {
                throw new \Exception('Lỗi upload file lên Cloudinary');
            }
        }

        $message = $this->messageRepository->create([
            'sender_id'          => $userId,
            'conversation_id'    => $conversationId,
            'content'            => $content,
            'message_type'       => $messageType ?: 'text',
            'file_url'           => $fileUrl,
            'file_provider'      => $fileProvider,
            'file_public_id'     => $filePublicId,
            'file_name'          => $fileName,
            'file_size'          => $fileSize,
            'mime_type'          => $mimeType,
            'reply_to_message_id'=> $replyToMessageId,
        ]);

        // Update latest_message_id
        $this->conversationRepository->update($conversationId, ['latest_message_id' => $message->id]);

        return $message->load(['sender', 'readBy', 'conversation.users']);
    }

    /**
     * Tạo system message (không có sender_id thật – dùng null hoặc system user).
     * Vì sender_id có FK constraint, ta dùng auth()->id() làm fallback hoặc bot user.
     */
    public function createSystemMessage($conversationId, $content)
    {
        $message = $this->messageRepository->create([
            'sender_id'       => auth()->id(),
            'conversation_id' => $conversationId,
            'content'         => $content,
            'message_type'    => 'system',
        ]);

        $this->conversationRepository->update($conversationId, ['latest_message_id' => $message->id]);

        return $message->load(['sender', 'readBy', 'conversation.users']);
    }

    public function editMessage($messageId, $userId, $newContent)
    {
        $message = $this->messageRepository->findById($messageId);
        if (!$message) throw new \Exception('Message not found', 404);
        if ((int)$message->sender_id !== (int)$userId) throw new \Exception('Unauthorized', 403);
        if ($message->message_type !== 'text') throw new \Exception('Chỉ có thể sửa tin nhắn text', 400);
        if ($message->is_deleted_for_all) throw new \Exception('Tin nhắn đã bị thu hồi', 400);

        $this->messageRepository->update($messageId, ['content' => $newContent]);
        return $this->messageRepository->findById($messageId)->load(['sender', 'readBy']);
    }

    public function recallMessage($messageId, $userId)
    {
        $message = $this->messageRepository->findById($messageId);
        if (!$message) throw new \Exception('Message not found', 404);
        if ((int)$message->sender_id !== (int)$userId) throw new \Exception('Unauthorized', 403);

        $this->messageRepository->update($messageId, [
            'is_deleted_for_all' => true,
            'content'            => 'Tin nhắn đã bị thu hồi',
            'deleted_at'         => now(),
        ]);

        return $this->messageRepository->findById($messageId)->load(['sender', 'readBy']);
    }

    public function formatMessage($msg)
    {
        $sender = $msg->sender ? [
            '_id'      => (string) $msg->sender->id,
            'fullName' => $msg->sender->full_name,
            'avatar'   => $msg->sender->avatar,
        ] : null;

        $readBy = $msg->readBy ? $msg->readBy->map(fn($u) => [
            '_id'      => (string) $u->id,
            'fullName' => $u->full_name,
            'avatar'   => $u->avatar,
        ])->toArray() : [];

        return [
            '_id'               => (string) $msg->id,
            'id'                => $msg->id,
            'conversationId'    => (string) $msg->conversation_id,
            'sender'            => $sender,
            'content'           => $msg->content,
            'messageType'       => $msg->message_type,
            'fileUrl'           => $msg->file_url,
            'fileName'          => $msg->file_name,
            'fileSize'          => $msg->file_size,
            'mimeType'          => $msg->mime_type,
            'isDeletedForAll'   => $msg->is_deleted_for_all,
            'replyToMessageId'  => $msg->reply_to_message_id ? (string) $msg->reply_to_message_id : null,
            'readBy'            => $readBy,
            'createdAt'         => $msg->created_at,
            'updatedAt'         => $msg->updated_at,
        ];
    }
}
