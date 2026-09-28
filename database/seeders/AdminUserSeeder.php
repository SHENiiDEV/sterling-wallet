<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Creates the initial super admin from ADMIN_* env variables.
     * When ADMIN_PASSWORD is empty a random one is generated and printed once.
     */
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@sterling.local');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists, skipping.");

            return;
        }

        $password = (string) env('ADMIN_PASSWORD') ?: Str::password(16, symbols: false);

        User::query()->create([
            'name' => (string) env('ADMIN_NAME', 'Administrator'),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ])->forceFill(['email_verified_at' => now()])->save();

        $this->command?->info("Super admin created: {$email}");

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}
