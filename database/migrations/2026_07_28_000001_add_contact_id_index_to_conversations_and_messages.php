<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `foreignId()->constrained()` en Postgres NO crea un índice secundario para la
 * FK (a diferencia de MySQL/InnoDB, que sí lo autogenera). Sin esto, cada
 * withCount(['conversations', 'messages']) del listado de contactos hace un
 * seq scan de esas tablas por cada fila de la página. Se usa CONCURRENTLY para
 * no bloquear escrituras mientras se construye el índice.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_contact_id_index ON conversations (contact_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS messages_contact_id_index ON messages (contact_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_contact_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_contact_id_index');
    }
};
