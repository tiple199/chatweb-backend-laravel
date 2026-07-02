<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    // GET /users/profile
    public function profile(Request $request)
    {
        $user = auth()->user();
        return response()->json([
            'success' => true,
            'data' => $this->formatUser($user)
        ]);
    }

    // GET /users/:id
    public function getUserById($id)
    {
        $user = \App\Models\User::find($id);
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User không tồn tại'], 404);
        }
        return response()->json([
            'success' => true,
            'data' => $this->formatUser($user)
        ]);
    }

    // PUT /users/avatar – Upload avatar (field: file, max 10MB)
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        try {
            $user = auth()->user();
            $user = $this->userService->updateAvatar($user, $request->file('file'));

            // Refresh from DB to get updated avatar URL
            if (!is_object($user) || !isset($user->id)) {
                $user = auth()->user()->fresh();
            }

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật avatar thành công',
                'data'    => $this->formatUser($user)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi upload ảnh: ' . $e->getMessage()
            ], 500);
        }
    }

    private function formatUser($user)
    {
        return [
            '_id'       => (string) $user->id,
            'id'        => $user->id,
            'fullName'  => $user->full_name,
            'email'     => $user->email,
            'avatar'    => $user->avatar,
            'createdAt' => $user->created_at,
            'updatedAt' => $user->updated_at,
        ];
    }
}
