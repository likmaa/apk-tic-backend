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
        // 1. Security Check: Only Developer can create users here
        $currentUser = $request->user();
        if (!$currentUser || $currentUser->role !== 'developer') {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        // 2. Validation
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            // Phone is optional or required depending on logic, let's say unique if present
            'phone' => ['required', 'string', 'max:50', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            // Role restriction: developer can create admins, drivers, passengers. 
            // Creating another developer might be restricted or allowed. Let's allow strictly 'admin' for now as per request
            // but the UI allows picking role.
            'role' => ['required', 'in:admin,driver,passenger'], 
        ]);

        // 3. Creation
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => true,
            'phone_verified_at' => now(), // Auto-verify admins created by super-admin
        ]);

        return response()->json($user, 201);
    }
}
