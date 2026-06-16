<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    // Añade 'delivered' al check constraint de messages.status: el webhook de
    // status de las campañas masivas necesita reflejar "entregado" en el chat,
    // no solo "enviado"/"leído".
    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_status_check');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('sent', 'received', 'read', 'failed', 'delivered'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_status_check');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('sent', 'received', 'read', 'failed'))");
    }
};
