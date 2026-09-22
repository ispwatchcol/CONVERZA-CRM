<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    // Añade 'portal' al check constraint de support_tickets.source: hasta ahora
    // todos los tickets los tecleábamos nosotros (`manual`), y con el portal del
    // ISP hace falta distinguir el que abrió el cliente por su cuenta — es la
    // diferencia entre "nos llamó y lo anoté" y "entró solo y nadie lo ha visto".
    public function up(): void
    {
        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT support_tickets_source_check');
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_source_check CHECK (source IN ('manual', 'whatsapp', 'email', 'portal'))");
    }

    public function down(): void
    {
        // Los tickets que ya entraron por el portal quedarían fuera del constraint
        // viejo, así que se reetiquetan como 'manual' antes de apretarlo.
        DB::table('support_tickets')->where('source', 'portal')->update(['source' => 'manual']);

        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT support_tickets_source_check');
        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_source_check CHECK (source IN ('manual', 'whatsapp', 'email'))");
    }
};
