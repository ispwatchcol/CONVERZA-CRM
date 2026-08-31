<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice para la comprobación de pertenencia de `MediaController::serve`.
 *
 * Cada archivo que el chat muestra —cada miniatura, cada nota de voz— dispara
 * ahora un `where tenant_id = ? and media_path = ?`. Sin índice eso es un seq
 * scan de `messages` por medio servido, y un chat con imágenes abre decenas de
 * peticiones a la vez.
 *
 * Es un índice PARCIAL: `media_path` es NULL en la gran mayoría de los mensajes
 * (todo el texto), y esas filas no se consultan nunca por esta vía porque la
 * ruta pedida siempre trae un path. Indexar solo las filas con medio deja el
 * índice en una fracción del tamaño y sirve igual a la consulta.
 *
 * CONCURRENTLY para no bloquear escrituras mientras se construye — mismo
 * patrón que 2026_07_28_000001. Exige `withinTransaction = false`: Postgres
 * rechaza CREATE INDEX CONCURRENTLY dentro de una transacción.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS messages_tenant_id_media_path_index
             ON messages (tenant_id, media_path)
             WHERE media_path IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_tenant_id_media_path_index');
    }
};
