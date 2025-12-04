<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminAndDeveloperSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::updateOrCreate(
            ['phone' => '+10000000001'],
            [
                'name' => 'Admin User',
                'email' => 'admin@example.local',
                'password' => Hash::make('admin-password'),
                'role' => 'admin',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );

        // Developer user
        User::updateOrCreate(
            ['phone' => '+10000000002'],
            [
                'name' => 'Developer User',
                'email' => 'developer@example.local',
                'password' => Hash::make('developer-password'),
                'role' => 'developer',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );
    }
}
