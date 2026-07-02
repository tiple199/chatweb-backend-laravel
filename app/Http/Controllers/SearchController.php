<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    // GET /search?q=keyword
    public function search(Request $request)
    {
        $keyword   = $request->query('q', '');
        $currentId = auth()->id();

        if (strlen(trim($keyword)) < 1) {
            return response()->json(['success' => true, 'data' => ['users' => []]]);
        }

        $users = User::where('id', '!=', $currentId)
            ->where(function ($q) use ($keyword) {
                $q->where('full_name', 'LIKE', "%{$keyword}%")
                  ->orWhere('email', 'LIKE', "%{$keyword}%");
            })
            ->limit(20)
            ->get();

        $formatted = $users->map(fn($u) => [
            '_id'      => (string) $u->id,
            'id'       => $u->id,
            'fullName' => $u->full_name,
            'email'    => $u->email,
            'avatar'   => $u->avatar,
        ]);

        return response()->json([
            'success' => true,
            'data' => ['users' => $formatted]
        ]);
    }
}
