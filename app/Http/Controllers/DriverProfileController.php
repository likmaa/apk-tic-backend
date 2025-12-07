<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DriverProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

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
                'contract_accepted_at' => $profile->contract_accepted_at,
                'created_at' => $profile->created_at,
                'updated_at' => $profile->updated_at,
            ] : null,
        ]);
    }

    public function store(Request $request)
{
    $user = $request->user();

    $data = $request->validate([
        'vehicle_number' => ['nullable', 'string', 'max:64'],
        'license_number' => ['required', 'string', 'max:64'],
        'photo' => ['nullable', 'string', 'max:255'],
        'documents' => ['nullable', 'array'],
    ]);

    $profile = null;

    DB::transaction(function () use ($user, $data, &$profile) {
        // vehicle_number peut être absent ou null
        $user->vehicle_number = $data['vehicle_number'] ?? null;
        $user->license_number = $data['license_number'];

        // Bascule le compte en role "driver" pour qu'il apparaisse dans la modération
        if (($user->role ?? null) !== 'driver') {
        }

        if (!empty($data['photo'])) {
            $user->photo = $data['photo'];
        }

        $user->save();

        $payload = [
            'vehicle_number' => $data['vehicle_number'] ?? null,
            'license_number' => $data['license_number'],
            'photo' => $data['photo'] ?? $user->photo,
            'status' => 'pending',
            'updated_at' => now(),
        ];

        if (array_key_exists('documents', $data)) {
            $payload['documents'] = $data['documents'] !== null
                ? json_encode($data['documents'])
                : null;
        }

        DB::table('driver_profiles')->updateOrInsert(
            ['user_id' => $user->id],
            $payload + ['created_at' => now()]
        );

        $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();
    });

    return response()->json([
        'ok' => true,
        'message' => 'Driver profile submitted, waiting for admin approval.',
        'profile' => $profile ? [
            'status' => $profile->status,
            'vehicle_number' => $profile->vehicle_number,
            'license_number' => $profile->license_number,
            'photo' => $profile->photo,
            'documents' => $profile->documents ? json_decode($profile->documents, true) : null,
            'contract_accepted_at' => $profile->contract_accepted_at,
            'created_at' => $profile->created_at,
            'updated_at' => $profile->updated_at,
        ] : null,
    ], 201);
}

    public function acceptContract(Request $request)
    {
        $user = $request->user();

        // Vérifier si le profil existe, sinon le créer
        $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();
        
        if (!$profile) {
            // Créer le profil driver avec status='pending' si il n'existe pas
            DB::table('driver_profiles')->insert([
                'user_id' => $user->id,
                'status' => 'pending',
                'contract_accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // Mettre à jour le contrat accepté
            DB::table('driver_profiles')
                ->where('user_id', $user->id)
                ->update([
                    'contract_accepted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        // Récupérer le profil mis à jour pour retourner contract_accepted_at
        $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();

        return response()->json([
            'ok' => true,
            'user_id' => $user->id,
            'contract_accepted_at' => $profile->contract_accepted_at ?? now()->toIso8601String(),
        ]);
    }
}
