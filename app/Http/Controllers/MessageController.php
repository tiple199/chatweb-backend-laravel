<?php

namespace App\Http\Controllers;

use App\Services\MessageService;
use App\Services\ConversationService;
use App\Events\MessageSent;
use App\Events\NewMessageNotification;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    protected $messageService;
    protected $conversationService;

    public function __construct(MessageService $messageService, ConversationService $conversationService)
    {
        $this->messageService      = $messageService;
        $this->conversationService = $conversationService;
    }

    // GET /messages/:conversationId?page=1&limit=30
    public function getHistory(Request $request, $conversationId)
    {
        $page  = (int) $request->query('page', 1);
        $limit = (int) $request->query('limit', 30);

        $result = $this->messageService->getHistory($conversationId, auth()->id(), $page, $limit);

        $formatted = array_map(fn($msg) => $this->messageService->formatMessage($msg), $result['messages']);

        return response()->json([
            'success' => true,
            'data' => [
                'messages'     => $formatted,
                'currentPage'  => $result['currentPage'],
                'totalPages'   => $result['totalPages'],
                'totalMessages'=> $result['totalMessages'],
            ]
        ]);
    }

    // GET /messages/search?conversationId=x&keyword=y&messageType=z
    public function searchMessages(Request $request)
    {
        $keyword        = $request->query('keyword', '');
        $conversationId = $request->query('conversationId');
        $messageType    = $request->query('messageType');

        $messages = $this->messageService->searchMessages($keyword, $conversationId, auth()->id(), $messageType);

        $formatted = array_map(fn($m) => $this->messageService->formatMessage($m), $messages);

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    // POST /messages – gửi tin nhắn (text / file)
    public function sendMessage(Request $request)
    {
        $request->validate(['conversationId' => 'required']);

        $conversationId   = $request->conversationId;
        $content          = $request->input('content', '');
        $messageType      = $request->input('messageType', 'text');
        $replyToMessageId = $request->input('replyToMessageId');
        $file             = $request->hasFile('file') ? $request->file('file') : null;

        // --- Chain of Responsibility Pipeline ---
        $pipeline = app(\App\Pipeline\MessagePipeline::class);
        $context  = [
            'conversationId' => $conversationId,
            'userId'         => auth()->id(),
            'content'        => $content,
            'file'           => $file,
        ];

        try {
            $pipeline->process($context);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
        // --- End Pipeline ---

        try {
            $message    = $this->messageService->sendMessage(
                $conversationId,
                auth()->id(),
                $content,
                $messageType,
                $replyToMessageId,
                $file
            );
            $fmtMessage = $this->messageService->formatMessage($message);

            // Broadcast real-time
            broadcast(new MessageSent($fmtMessage));

            // Notification to all other participants
            $conversation = $message->conversation;
            $chatName = $conversation->is_group_chat
                ? $conversation->chat_name
                : ($fmtMessage['sender']['fullName'] ?? '');

            foreach ($conversation->users as $user) {
                if ($user->id !== auth()->id()) {
                    broadcast(new NewMessageNotification(
                        $user->id,
                        (string) $conversationId,
                        [
                            'fullName' => $fmtMessage['sender']['fullName'] ?? '',
                            'avatar'   => $fmtMessage['sender']['avatar']   ?? null,
                        ],
                        $chatName,
                        $content,
                        $fmtMessage['messageType']
                    ))->toOthers();
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $fmtMessage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi gửi tin nhắn: ' . $e->getMessage()
            ], 500);
        }
    }

    // PUT /messages/:messageId – sửa tin nhắn (chỉ text)
    public function editMessage(Request $request, $messageId)
    {
        $request->validate(['content' => 'required|string']);

        try {
            $message    = $this->messageService->editMessage($messageId, auth()->id(), $request->content);
            $fmtMessage = $this->messageService->formatMessage($message);

            broadcast(new \App\Events\MessageUpdated($fmtMessage));

            return response()->json(['success' => true, 'data' => $fmtMessage]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    // DELETE /messages/:messageId – thu hồi tin nhắn
    public function recallMessage($messageId)
    {
        try {
            $message    = $this->messageService->recallMessage($messageId, auth()->id());
            $fmtMessage = $this->messageService->formatMessage($message);

            broadcast(new \App\Events\MessageDeleted($fmtMessage));

            return response()->json(['success' => true, 'data' => $fmtMessage]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }
}
