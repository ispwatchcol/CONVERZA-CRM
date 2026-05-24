<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BackfillTenantSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenant = Tenant::updateOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default Tenant',
                'is_active' => true,
                'wa_status' => 'disconnected',
            ],
        );

        $tables = [
            'contacts', 'labels', 'teams', 'staff_members',
            'conversations', 'messages', 'quick_replies',
            'closing_notes', 'templates', 'users',
        ];

        foreach ($tables as $table) {
            $updated = DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $tenant->id]);
            $this->command->info("  {$table}: {$updated} fila(s) backfilled");
        }

        $this->command->info("Tenant default id={$tenant->id} listo.");
    }
}
