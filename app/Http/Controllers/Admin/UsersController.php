<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->query('role');
        $q = $request->query('q');

        $query = User::query();
        if ($role) {
            $query->where('role', $role);
        }
        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%$q%");
                $sub->orWhere('phone', 'like', "%$q%");
                $sub->orWhere('email', 'like', "%$q%");
            });
        }

        $users = $query->orderByDesc('id')->paginate(20);
        return response()->json($users);
    }

    public function show(int $id)
    {
        $user = User::findOrFail($id);
        return response()->json($user);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'email' => ['sometimes','email'],
            'phone' => ['sometimes','string','max:32'],
            'role' => ['sometimes','in:admin,developer,driver,passenger'],
            'is_active' => ['sometimes','boolean'],
        ]);
        $user = User::findOrFail($id);
        $user->fill($data);
        $user->save();
        return response()->json($user);
    }

    public function destroy(int $id)
    {
        $u = User::findOrFail($id);
        $u->delete();
        return response()->json(['ok' => true]);
    }
}
