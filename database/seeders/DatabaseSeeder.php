<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(BackfillTenantSeeder::class);

        $tenant = \App\Models\Tenant::where('slug', 'default')->first();

        User::updateOrCreate(
            ['email' => 'admin@converza.com'],
            [
                'tenant_id' => $tenant?->id,
                'name' => 'Admin Converza',
                'password' => 'converza2024',
            ],
        );
    }
}
