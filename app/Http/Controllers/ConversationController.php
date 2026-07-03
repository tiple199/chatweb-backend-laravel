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

            // Broadcast GroupAdded to all participants
            $currentUser = auth()->user();
            foreach ($request->users as $userId) {
                broadcast(new \App\Events\GroupAdded(
                    $userId,
                    "Bạn đã được thêm vào nhóm " . $groupChat->chat_name . " bởi " . $currentUser->full_name,
                    $groupChat->id
                ));
            }

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
            $conversation = \App\Models\Conversation::find($conversationId);
            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Nhóm không tồn tại'], 404);
            }

            $userId = auth()->id();
            $isCreator = (int)$conversation->creator_id === (int)$userId;

            if ($isCreator) {
                // Phát event cho tất cả thành viên trong nhóm biết nhóm đã bị giải tán
                broadcast(new \App\Events\ParticipantsUpdated((string)$conversationId, (string)$userId, 'disband'))->toOthers();
                
                // Giải tán nhóm - Xóa latest_message_id để tránh ràng buộc khóa ngoại vòng lặp
                $conversation->update(['latest_message_id' => null]);
                $conversation->delete();

                return response()->json(['success' => true, 'message' => 'Chủ nhóm rời nhóm. Đã giải tán nhóm thành công']);
            }

            // Normal leaving logic
            $this->conversationService->leaveGroup($conversationId, $userId);

            // System message
            $msg = $this->messageService->createSystemMessage(
                $conversationId,
                auth()->user()->full_name . ' đã rời khỏi nhóm'
            );
            $fmtMsg = $this->messageService->formatMessage($msg);
            broadcast(new MessageSent($fmtMsg));

            // Notify participants updated
            broadcast(new \App\Events\ParticipantsUpdated((string)$conversationId, (string)$userId, 'leave'))->toOthers();

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
        $conversation = \App\Models\Conversation::find($conversationId);

        $formatted = collect($users)->map(function ($user) use ($conversation) {
            $role = 'member';
            if ($conversation && (string) $user->id === (string) $conversation->creator_id) {
                $role = 'creator';
            } elseif ($user->pivot->is_admin) {
                $role = 'admin';
            }

            return [
                'userId'   => (string) $user->id,
                'fullName' => $user->full_name,
                'email'    => $user->email,
                'avatar'   => $user->avatar,
                'role'     => $role,
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

        $conversation = \App\Models\Conversation::find($conversationId);
        $chatName = $conversation ? $conversation->chat_name : 'nhóm';

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

        // Broadcast GroupAdded to the added user
        broadcast(new \App\Events\GroupAdded(
            $request->userId,
            "Bạn đã được thêm vào nhóm " . $chatName . " bởi " . $currentUser->full_name,
            $conversationId
        ));

        // Broadcast participants_updated
        broadcast(new ParticipantsUpdated($conversationId, $request->userId, 'add'))->toOthers();

        return response()->json(['success' => true, 'message' => 'Đã thêm thành viên']);
    }

    // DELETE /conversations/:conversationId/participants/:userId – xóa thành viên
    public function removeMember($conversationId, $userId)
    {
        $currentUser = auth()->user();

        $conversation = \App\Models\Conversation::find($conversationId);
        if (!$conversation) {
            return response()->json(['success' => false, 'message' => 'Nhóm không tồn tại'], 404);
        }

        // Get rank of current user
        $currentPivot = $conversation->users()->where('users.id', $currentUser->id)->first()?->pivot;
        if (!$currentPivot) {
            return response()->json(['success' => false, 'message' => 'Bạn không phải là thành viên nhóm'], 403);
        }
        $currentUserRank = 1;
        if ((int)$conversation->creator_id === (int)$currentUser->id) {
            $currentUserRank = 3;
        } elseif ($currentPivot->is_admin) {
            $currentUserRank = 2;
        }

        // Get rank of target user
        $targetPivot = $conversation->users()->where('users.id', $userId)->first()?->pivot;
        if (!$targetPivot) {
            return response()->json(['success' => false, 'message' => 'Thành viên không tồn tại trong nhóm'], 404);
        }
        $targetUserRank = 1;
        if ((int)$conversation->creator_id === (int)$userId) {
            $targetUserRank = 3;
        } elseif ($targetPivot->is_admin) {
            $targetUserRank = 2;
        }

        // Hierarchy rule: Rank of current user must be strictly greater than rank of target user
        if ($currentUserRank <= $targetUserRank) {
            return response()->json([
                'success' => false, 
                'message' => 'Bạn không đủ quyền để xóa thành viên này'
            ], 403);
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
            $newAdminState = $this->conversationService->grantAdmin($conversationId, $currentUser->id, $userId);
            
            $targetUser = \App\Models\User::find($userId);
            if ($targetUser) {
                $actionText = $newAdminState ? 'cấp quyền quản trị cho' : 'gỡ quyền quản trị của';
                $msg = $this->messageService->createSystemMessage(
                    $conversationId,
                    $currentUser->full_name . " đã $actionText " . $targetUser->full_name
                );
                $fmtMsg = $this->messageService->formatMessage($msg);
                broadcast(new MessageSent($fmtMsg));
            }

            broadcast(new ParticipantsUpdated($conversationId, $userId, 'update_role'))->toOthers();

            $msgText = $newAdminState ? 'Đã cấp quyền quản trị' : 'Đã gỡ quyền quản trị';
            return response()->json(['success' => true, 'message' => $msgText]);
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
                'CreatorName'     => $note->creator ? $note->creator->full_name : null,
                'CreatorAvatar'   => $note->creator ? $note->creator->avatar : null,
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
                'CreatorName'     => auth()->user()->full_name,
                'CreatorAvatar'   => auth()->user()->avatar,
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
            'creatorId'       => $conv->creator_id ? (string) $conv->creator_id : null,
            'createdAt'       => $conv->created_at,
            'updatedAt'       => $conv->updated_at,
        ];
    }
}
