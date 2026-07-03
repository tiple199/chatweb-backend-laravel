<?php

namespace App\Http\Controllers;

use App\Services\FriendService;
use Illuminate\Http\Request;

class FriendController extends Controller
{
    protected $friendService;

    public function __construct(FriendService $friendService)
    {
        $this->friendService = $friendService;
    }

    public function index(Request $request)
    {
        $userId = auth()->id();
        
        $friends = $this->friendService->getFriends($userId);

        $friendUsers = collect($friends)->map(function($user) {
            return $this->formatUser($user);
        });

        return response()->json([
            'success' => true,
            'data' => $friendUsers
        ]);
    }

    public function getFriendRequests(Request $request)
    {
        $userId = auth()->id();
        
        $requests = $this->friendService->getPendingRequests($userId);

        $formatted = collect($requests)->map(function($req) {
            return [
                '_id' => (string) $req->id,
                'id' => $req->id,
                'sender' => $this->formatUser($req->requester),
                'status' => $req->status,
                'createdAt' => $req->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    public function getFriendStatus(Request $request, $targetUserId)
    {
        $status = $this->friendService->getFriendStatus(auth()->id(), $targetUserId);
        
        return response()->json(['success' => true, 'status' => $status]);
    }

    public function sendFriendRequest(Request $request)
    {
        $request->validate(['recipientId' => 'required']);
        $user = auth()->user();

        try {
            $friend = $this->friendService->sendRequest($user->id, $user->full_name, $request->recipientId);
            
            return response()->json([
                'success' => true,
                'message' => 'Đã gửi lời mời kết bạn',
                'data' => $friend
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function acceptFriendRequest(Request $request)
    {
        $request->validate(['requestId' => 'required']);
        $user = auth()->user();

        try {
            $this->friendService->acceptRequest($user->id, $user->full_name, $request->requestId);
            return response()->json(['success' => true, 'message' => 'Đã chấp nhận kết bạn']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function declineFriendRequest(Request $request)
    {
        $request->validate(['requestId' => 'required']);
        
        $this->friendService->declineRequest(auth()->id(), $request->requestId);
        
        return response()->json(['success' => true, 'message' => 'Đã từ chối kết bạn']);
    }

    public function cancelFriendRequest(Request $request)
    {
        $request->validate(['recipientId' => 'required']);
        
        $this->friendService->cancelRequest(auth()->id(), $request->recipientId);

        return response()->json(['success' => true, 'message' => 'Đã hủy lời mời']);
    }

    public function unfriend(Request $request, $friendId)
    {
        $this->friendService->unfriend(auth()->id(), $friendId);

        return response()->json(['success' => true, 'message' => 'Đã hủy kết bạn']);
    }

    private function formatUser($user) {
        return [
            '_id' => (string) $user->id,
            'id' => $user->id,
            'fullName' => $user->full_name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'isVerified' => $user->is_verified,
        ];
    }
}
