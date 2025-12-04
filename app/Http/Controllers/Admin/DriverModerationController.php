<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverModerationController extends Controller
{
    public function indexPending(Request $request)
    {
        $drivers = User::query()
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'pending')
            ->select(
                'users.id',
                'users.name',
                'users.phone',
                'users.role',
                'driver_profiles.status',
                'driver_profiles.vehicle_number',
                'driver_profiles.license_number'
            )
            ->orderByDesc('users.id')
            ->paginate(20);

        return response()->json($drivers);
    }

    public function indexApproved(Request $request)
    {
        $drivers = User::query()
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->select(
                'users.id',
                'users.name',
                'users.phone',
                'users.role',
                'driver_profiles.status',
                'driver_profiles.vehicle_number',
                'driver_profiles.license_number'
            )
            ->orderByDesc('users.id')
            ->paginate(50);

        return response()->json($drivers);
    }

    public function online(Request $request)
    {
        $query = User::query()
            ->where('role', 'driver')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                'users.phone',
                'users.email',
                'users.is_online',
                'users.last_lat',
                'users.last_lng',
                'users.last_location_at',
                'driver_profiles.status',
                'driver_profiles.vehicle_number',
                'driver_profiles.license_number',
                'driver_profiles.documents',
            )
            ->orderByDesc('users.id');

        // Optional filter: ?online=1 or ?online=0 to restrict results
        $online = $request->query('online');
        if ($online !== null && $online !== '') {
            if (in_array($online, ['1', 'true', 1, true], true)) {
                $query->where('users.is_online', true);
            } elseif (in_array($online, ['0', 'false', 0, false], true)) {
                $query->where('users.is_online', false);
            }
        }

        $drivers = $query->get();

        return response()->json($drivers);
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,approved,rejected'],
        ]);

        $user = User::where('id', $id)->firstOrFail();

        DB::transaction(function () use ($user, $data) {
            DB::table('driver_profiles')->updateOrInsert(
                ['user_id' => $user->id],
                ['status' => $data['status']]
            );

            if ($data['status'] === 'approved' && $user->role !== 'driver') {
                $user->role = 'driver';
                $user->save();
            }
        });

        return response()->json(['ok' => true, 'user_id' => $user->id, 'status' => $data['status']]);
    }

    public function location(int $id)
    {
        $driver = User::where('id', $id)->where('role', 'driver')->firstOrFail();

        return response()->json([
            'id' => $driver->id,
            'name' => $driver->name,
            'phone' => $driver->phone,
            'is_online' => (bool) ($driver->is_online ?? false),
            'last_lat' => $driver->last_lat,
            'last_lng' => $driver->last_lng,
            'last_location_at' => $driver->last_location_at,
        ]);
    }

    public function showProfile(int $id)
    {
        $user = User::findOrFail($id);

        $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'role' => $user->role,
                'vehicle_number' => $user->vehicle_number,
                'license_number' => $user->license_number,
                'photo' => $user->photo,
            ],
            'profile' => $profile ? [
                'status' => $profile->status,
                'vehicle_number' => $profile->vehicle_number,
                'license_number' => $profile->license_number,
                'photo' => $profile->photo,
                'documents' => $profile->documents ? json_decode($profile->documents, true) : null,
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ] : null,
        ]);
    }

    public function forceOffline(int $id)
    {
        $driver = User::query()
            ->where('id', $id)
            ->where('role', 'driver')
            ->firstOrFail();

        $driver->is_online = false;
        $driver->save();

        return response()->json([
            'ok' => true,
            'user_id' => $driver->id,
            'is_online' => (bool) $driver->is_online,
        ]);
    }
}
