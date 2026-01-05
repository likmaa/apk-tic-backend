<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminAndDeveloperSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = env('ADMIN_SEED_PASSWORD');
        $devPassword = env('DEV_SEED_PASSWORD');

        if (empty($adminPassword) || empty($devPassword)) {
            throw new \RuntimeException(
                'ADMIN_SEED_PASSWORD and DEV_SEED_PASSWORD must be set in .env before running this seeder.'
            );
        }

        // Admin user
        User::updateOrCreate(
            ['phone' => '+10000000001'],
            [
                'name' => 'Admin User',
                'email' => 'admin@ticmiton.com',
                'password' => Hash::make($adminPassword),
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
                'password' => Hash::make($devPassword),
                'role' => 'developer',
                'is_active' => true,
                'phone_verified_at' => now(),
            ]
        );
    }
}
