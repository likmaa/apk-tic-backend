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
                'email' => 'admin@ticmiton.com',
                'password' => Hash::make('4vVgAnbSH4EJ@T_'),
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
                'email' => 'dev@ticmiton.com',
                'password' => Hash::make('M@likGlo@2026'),
                'role' => 'developer',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );
    }
}
