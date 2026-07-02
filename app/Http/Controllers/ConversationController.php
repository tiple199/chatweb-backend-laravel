<?php

namespace App\Http\Controllers;

use App\Services\ConversationService;
use App\Services\MessageService;
use App\Events\MessageSent;
use App\Events\NewMessageNotification;
use App\Events\ParticipantsUpdated;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    protected $conversationService;
    protected $messageService;

    public function __construct(ConversationService $conversationService, MessageService $messageService)
    {
        $this->conversationService = $conversationService;
        $this->messageService = $messageService;
    }

    // GET /conversations
    public function index(Request $request)
    {
        $userId = auth()->id();
        $conversations = $this->conversationService->getUserConversations($userId);

        $formatted = array_map(fn($conv) => $this->formatConversation($conv, $userId), $conversations);

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    // POST /conversations – tạo/truy cập chat 1-1
    public function accessChat(Request $request)
    {
        $request->validate(['userId' => 'required']);
        $currentUserId = auth()->id();
        $targetUserId  = $request->userId;

        $conversation = $this->conversationService->accessDirectChat($currentUserId, $targetUserId);

        return response()->json([
            'success' => true,
            'data' => $this->formatConversation($conversation, $currentUserId)
        ]);
    }

    // POST /conversations/group – tạo nhóm
    public function createGroup(Request $request)
    {
        $request->validate([
            'chatName' => 'required|string',
            'users'    => 'required|array|min:1',
        ]);

        try {
            $groupChat = $this->conversationService->createGroupChat(
                $request->chatName,
                $request->users,
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'data' => $this->formatConversation($groupChat, auth()->id())
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // PUT /conversations/:id – đổi tên nhóm
    public function updateConversation(Request $request, $id)
    {
        $request->validate(['chatName' => 'required|string']);

        $conversation = $this->conversationService->updateConversationName($id, $request->chatName);

        return response()->json([
            'success' => true,
            'data' => $this->formatConversation($conversation, auth()->id())
        ]);
    }

    // DELETE /conversations/:conversationId – xóa lịch sử chat
    public function clearHistory($conversationId)
    {
        $this->conversationService->clearHistory($conversationId, auth()->id());

        return response()->json(['success' => true, 'message' => 'Đã xóa lịch sử chat']);
    }

    // DELETE /conversations/:conversationId/leave – rời nhóm
    public function leaveGroup($conversationId)
    {
        try {
            $this->conversationService->leaveGroup($conversationId, auth()->id());

            // System message
            $msg = $this->messageService->createSystemMessage(
                $conversationId,
                auth()->user()->full_name . ' đã rời khỏi nhóm'
            );
            $fmtMsg = $this->messageService->formatMessage($msg);
            broadcast(new MessageSent($fmtMsg));

            // Nếu nhóm còn dưới 3 người
            $conversation = \App\Models\Conversation::find($conversationId);
            if ($conversation && $conversation->is_group_chat && $conversation->users()->count() < 3) {
                $closeMsg = $this->messageService->createSystemMessage(
                    $conversationId,
                    'Nhóm đã đóng do không đủ thành viên'
                );
                broadcast(new MessageSent($this->messageService->formatMessage($closeMsg)));
            }

            return response()->json(['success' => true, 'message' => 'Đã rời nhóm']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // PUT /conversations/:conversationId/read
    public function markAsRead($conversationId)
    {
        $this->conversationService->markAsRead($conversationId, auth()->id());

        return response()->json(['success' => true]);
    }

    // GET /conversations/:conversationId/participants
    public function getParticipants($conversationId)
    {
        $users = $this->conversationService->getParticipants($conversationId);

        $formatted = collect($users)->map(function ($user) {
            return [
                'userId'   => (string) $user->id,
                'fullName' => $user->full_name,
                'email'    => $user->email,
                'avatar'   => $user->avatar,
                'role'     => $user->pivot->is_admin ? 'admin' : 'member',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    // POST /conversations/:conversationId/participants – thêm thành viên
    public function addMember(Request $request, $conversationId)
    {
        $request->validate(['userId' => 'required']);
        $currentUser = auth()->user();

        // Check admin permission
        if (!$this->conversationService->isAdmin($conversationId, $currentUser->id)) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền thêm thành viên'], 403);
        }

        $addedUser = $this->conversationService->addMember($conversationId, $request->userId);

        // System message
        $addedUserObj = \App\Models\User::find($request->userId);
        if ($addedUserObj) {
            $msg = $this->messageService->createSystemMessage(
                $conversationId,
                $currentUser->full_name . ' đã thêm ' . $addedUserObj->full_name . ' vào nhóm'
            );
            $fmtMsg = $this->messageService->formatMessage($msg);
            broadcast(new MessageSent($fmtMsg));
        }

        // Broadcast participants_updated
        broadcast(new ParticipantsUpdated($conversationId, $request->userId, 'add'))->toOthers();

        return response()->json(['success' => true, 'message' => 'Đã thêm thành viên']);
    }

    // DELETE /conversations/:conversationId/participants/:userId – xóa thành viên
    public function removeMember($conversationId, $userId)
    {
        $currentUser = auth()->user();

        // Check admin permission
        if (!$this->conversationService->isAdmin($conversationId, $currentUser->id)) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền xóa thành viên'], 403);
        }

        $removedUserObj = \App\Models\User::find($userId);
        $this->conversationService->removeMember($conversationId, $userId);

        // System message
        if ($removedUserObj) {
            $msg = $this->messageService->createSystemMessage(
                $conversationId,
                $currentUser->full_name . ' đã xóa ' . $removedUserObj->full_name . ' khỏi nhóm'
            );
            $fmtMsg = $this->messageService->formatMessage($msg);
            broadcast(new MessageSent($fmtMsg));
        }
        
        // Nếu nhóm còn dưới 3 người
        $conversation = \App\Models\Conversation::find($conversationId);
        if ($conversation && $conversation->is_group_chat && $conversation->users()->count() < 3) {
            $closeMsg = $this->messageService->createSystemMessage(
                $conversationId,
                'Nhóm đã đóng do không đủ thành viên'
            );
            broadcast(new MessageSent($this->messageService->formatMessage($closeMsg)));
        }

        // Broadcast participants_updated
        broadcast(new ParticipantsUpdated($conversationId, $userId, 'remove'))->toOthers();

        return response()->json(['success' => true, 'message' => 'Đã xóa thành viên']);
    }

    // PUT /conversations/:conversationId/participants/:userId/admin
    public function grantAdmin($conversationId, $userId)
    {
        $currentUser = auth()->user();
        try {
            $this->conversationService->grantAdmin($conversationId, $currentUser->id, $userId);
            
            $targetUser = \App\Models\User::find($userId);
            if ($targetUser) {
                $msg = $this->messageService->createSystemMessage(
                    $conversationId,
                    $currentUser->full_name . ' đã cấp quyền quản trị cho ' . $targetUser->full_name
                );
                $fmtMsg = $this->messageService->formatMessage($msg);
                broadcast(new MessageSent($fmtMsg));
            }

            broadcast(new ParticipantsUpdated($conversationId, $userId, 'update_role'))->toOthers();

            return response()->json(['success' => true, 'message' => 'Đã cấp quyền quản trị']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    // ---- Polls ----

    // GET /conversations/:conversationId/polls
    public function getPolls($conversationId)
    {
        $polls = $this->conversationService->getPolls($conversationId);

        $formatted = array_map(function ($poll) {
            return [
                'PollId'          => (string) $poll->id,
                'ConversationId'  => (string) $poll->conversation_id,
                'Question'        => $poll->question,
                'IsActive'        => $poll->is_active,
                'CreatedByUserId' => (string) $poll->created_by,
                'CreatedAt'       => $poll->created_at,
                'Options'         => $poll->options->map(function ($opt) {
                    return [
                        'OptionId'   => (string) $opt->id,
                        'PollId'     => (string) $opt->poll_id,
                        'OptionText' => $opt->text,
                        'VoterIds'   => $opt->voters->pluck('id')->map(fn($id) => (string) $id)->toArray(),
                    ];
                })->toArray(),
            ];
        }, $polls);

        return response()->json(['success' => true, 'data' => $formatted]);
    }

    // POST /conversations/:conversationId/polls
    public function createPoll(Request $request, $conversationId)
    {
        $request->validate([
            'question' => 'required|string',
            'options'  => 'required|array|min:2',
        ]);

        $poll = $this->conversationService->createPoll(
            $conversationId,
            $request->question,
            $request->options,
            auth()->id()
        );

        // Poll message
        $message    = $this->messageService->sendMessage($conversationId, auth()->id(), (string) $poll->id, 'poll', null, null);
        $fmtMessage = $this->messageService->formatMessage($message);
        broadcast(new MessageSent($fmtMessage));
        $this->broadcastNotification($fmtMessage, $message->conversation, $conversationId);

        $formattedPoll = [
            'PollId'          => (string) $poll->id,
            'ConversationId'  => (string) $poll->conversation_id,
            'Question'        => $poll->question,
            'IsActive'        => $poll->is_active,
            'CreatedByUserId' => (string) $poll->created_by,
            'CreatedAt'       => $poll->created_at,
            'Options'         => $poll->load('options')->options->map(fn($opt) => [
                'OptionId'   => (string) $opt->id,
                'PollId'     => (string) $opt->poll_id,
                'OptionText' => $opt->text,
                'VoterIds'   => [],
            ])->toArray(),
        ];

        return response()->json(['success' => true, 'data' => $formattedPoll]);
    }

    // POST /polls/:pollId/vote
    public function votePoll(Request $request, $pollId)
    {
        $request->validate(['optionId' => 'required']);

        try {
            $this->conversationService->votePoll($pollId, $request->optionId, auth()->id());
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ---- Notes ----

    // GET /conversations/:conversationId/notes
    public function getNotes($conversationId)
    {
        $notes = $this->conversationService->getNotes($conversationId);

        $formatted = array_map(function ($note) {
            return [
                'NoteId'          => (string) $note->id,
                'ConversationId'  => (string) $note->conversation_id,
                'Content'         => $note->content,
                'CreatedByUserId' => (string) $note->created_by,
                'CreatedAt'       => $note->created_at,
                'UpdatedAt'       => $note->updated_at,
            ];
        }, $notes);

        return response()->json(['success' => true, 'data' => $formatted]);
    }

    // POST /conversations/:conversationId/notes
    public function createNote(Request $request, $conversationId)
    {
        $request->validate(['content' => 'required|string']);

        $note = $this->conversationService->createNote($conversationId, $request->content, auth()->id());

        // Note message
        $message    = $this->messageService->sendMessage($conversationId, auth()->id(), (string) $note->id, 'note', null, null);
        $fmtMessage = $this->messageService->formatMessage($message);
        broadcast(new MessageSent($fmtMessage));
        $this->broadcastNotification($fmtMessage, $message->conversation, $conversationId);

        return response()->json([
            'success' => true,
            'data' => [
                'NoteId'          => (string) $note->id,
                'ConversationId'  => (string) $note->conversation_id,
                'Content'         => $note->content,
                'CreatedByUserId' => (string) $note->created_by,
                'CreatedAt'       => $note->created_at,
                'UpdatedAt'       => $note->updated_at,
            ]
        ]);
    }

    // PUT /notes/:noteId
    public function updateNote(Request $request, $noteId)
    {
        $request->validate(['content' => 'required|string']);
        $this->conversationService->updateNote($noteId, $request->content);

        return response()->json(['success' => true]);
    }

    // DELETE /notes/:noteId
    public function deleteNote($noteId)
    {
        $this->conversationService->deleteNote($noteId);
        return response()->json(['success' => true]);
    }

    // ---- Private helpers ----

    private function broadcastNotification($formattedMessage, $conversation, $conversationId)
    {
        if (!$conversation) return;

        $chatName = $conversation->is_group_chat
            ? $conversation->chat_name
            : ($formattedMessage['sender']['fullName'] ?? '');

        foreach ($conversation->users as $user) {
            if ($user->id !== auth()->id()) {
                broadcast(new NewMessageNotification(
                    $user->id,
                    (string) $conversationId,
                    [
                        'fullName' => $formattedMessage['sender']['fullName'] ?? '',
                        'avatar'   => $formattedMessage['sender']['avatar']   ?? null,
                    ],
                    $chatName,
                    $formattedMessage['messageType'] === 'poll' ? 'Đã tạo bình chọn mới' : 'Đã tạo ghi chú mới',
                    $formattedMessage['messageType']
                ))->toOthers();
            }
        }
    }

    private function formatConversation($conv, $currentUserId = null)
    {
        $uid = $currentUserId ?? auth()->id();

        $users = $conv->users->map(fn($u) => [
            '_id'      => (string) $u->id,
            'id'       => $u->id,
            'fullName' => $u->full_name,
            'email'    => $u->email,
            'avatar'   => $u->avatar,
        ]);

        // Admins list
        $groupAdmins = $conv->users
            ->filter(fn($u) => $u->pivot->is_admin)
            ->pluck('id')
            ->map(fn($id) => (string) $id)
            ->values()
            ->toArray();

        // Latest message
        $latestMessage = null;
        if ($conv->latestMessage) {
            $lm = $conv->latestMessage;
            $latestMessage = [
                '_id'         => (string) $lm->id,
                'content'     => $lm->content,
                'messageType' => $lm->message_type,
                'sender'      => $lm->sender ? [
                    '_id'      => (string) $lm->sender->id,
                    'fullName' => $lm->sender->full_name,
                    'avatar'   => $lm->sender->avatar,
                ] : null,
                'createdAt' => $lm->created_at,
            ];
        }

        // For 1-1 chat: get other user info
        $chatName       = $conv->chat_name;
        $otherUserId    = null;
        $otherUserAvatar = null;

        if (!$conv->is_group_chat) {
            $otherUser = $conv->users->firstWhere('id', '!=', $uid);
            if ($otherUser) {
                $chatName        = $otherUser->full_name;
                $otherUserId     = (string) $otherUser->id;
                $otherUserAvatar = $otherUser->avatar;
            }
        }

        return [
            '_id'             => (string) $conv->id,
            'id'              => $conv->id,
            'conversationId'  => (string) $conv->id,
            'chatName'        => $chatName,
            'isGroupChat'     => $conv->is_group_chat,
            'groupAdmins'     => $groupAdmins,
            'users'           => $users,
            'latestMessage'   => $latestMessage,
            'otherUserId'     => $otherUserId,
            'otherUserAvatar' => $otherUserAvatar,
            'createdAt'       => $conv->created_at,
            'updatedAt'       => $conv->updated_at,
        ];
    }
}
