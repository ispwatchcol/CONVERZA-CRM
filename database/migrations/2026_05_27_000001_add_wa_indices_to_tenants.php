<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Lookups por webhook: resolvemos el tenant a partir de
            // metadata.phone_number_id (más específico) o entry.id (WABA).
            $table->index('wa_phone_number_id', 'tenants_wa_phone_number_id_idx');
            $table->index('wa_business_account_id', 'tenants_wa_business_account_id_idx');
            $table->index('wa_verify_token', 'tenants_wa_verify_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_wa_phone_number_id_idx');
            $table->dropIndex('tenants_wa_business_account_id_idx');
            $table->dropIndex('tenants_wa_verify_token_idx');
        });
    }
};
