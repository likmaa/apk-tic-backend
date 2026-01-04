<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $role = $request->query('role');
        $q = $request->query('q');

        $query = User::query();

        // 🛡️ Security: Admins should not see developers at all
        if ($currentUser->role === 'admin') {
            $query->where('role', '!=', 'developer');
            if ($role === 'developer') {
                return response()->json(['data' => [], 'total' => 0, 'current_page' => 1]);
            }
        }

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
        $currentUser = $request->user();
        $user = User::findOrFail($id);

        // Security Check: admins cannot modify developers or promote someone to developer
        if ($currentUser->role === 'admin') {
            if ($user->role === 'developer') {
                return response()->json(['message' => 'Un administrateur ne peut pas modifier un développeur.'], 403);
            }
            if ($request->has('role') && $request->role === 'developer') {
                return response()->json(['message' => 'Un administrateur ne peut pas promouvoir un utilisateur au rôle de développeur.'], 403);
            }
        }

        $data = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'email' => ['sometimes','email'],
            'phone' => ['sometimes','string','max:32'],
            'role' => ['sometimes','in:admin,developer,driver,passenger'],
            'is_active' => ['sometimes','boolean'],
        ]);

        $user->fill($data);
        $user->save();
        return response()->json($user);
    }

    public function destroy(Request $request, int $id)
    {
        $currentUser = $request->user();
        $u = User::findOrFail($id);
        
        // 🛡️ Security: Admins cannot delete developers
        if ($currentUser->role === 'admin' && $u->role === 'developer') {
            return response()->json(['message' => 'Un administrateur ne peut pas supprimer un développeur.'], 403);
        }
        
        // Supprimer les rides associés (en tant que rider ou driver) avant de supprimer l'utilisateur
        DB::transaction(function () use ($u) {
            // Récupérer les IDs des rides à supprimer
            $rideIds = Ride::where('rider_id', $u->id)
                ->orWhere('driver_id', $u->id)
                ->pluck('id');
            
            // Supprimer les ratings associés aux rides (ratings n'a pas de FK mais on nettoie pour la cohérence)
            if ($rideIds->isNotEmpty()) {
                DB::table('ratings')->whereIn('ride_id', $rideIds)->delete();
            }
            
            // Supprimer les rides où l'utilisateur est le passager (rider)
            Ride::where('rider_id', $u->id)->delete();
            
            // Supprimer les rides où l'utilisateur est le chauffeur (driver)
            Ride::where('driver_id', $u->id)->delete();
            
            // Supprimer les ratings où l'utilisateur est le driver ou le passenger
            DB::table('ratings')->where('driver_id', $u->id)->orWhere('passenger_id', $u->id)->delete();
            
            // Supprimer les driver_rewards associés
            DB::table('driver_rewards')->where('driver_id', $u->id)->delete();
            
            // Maintenant on peut supprimer l'utilisateur
            // Les autres tables (driver_profiles, wallets, payments, addresses) ont onDelete('cascade')
            // donc elles seront supprimées automatiquement
            $u->delete();
        });
        
        return response()->json(['ok' => true]);
    }

    public function store(Request $request)
    {
        // 1. Security Check
        $currentUser = $request->user();
        
        // 2. Validation
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:50', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:admin,developer,driver,passenger'], 
        ]);

        // 3. Extra Security Check for role creation
        if ($currentUser->role === 'admin' && $data['role'] === 'developer') {
            return response()->json(['message' => 'Un administrateur ne peut pas créer de compte développeur.'], 403);
        }

        // 4. Creation
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        return response()->json($user, 201);
    }
}
