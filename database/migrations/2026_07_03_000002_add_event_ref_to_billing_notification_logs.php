<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generaliza `billing_notification_logs` para que también sea la bitácora de los
 * avisos por EVENTO (bienvenida / pago registrado), no solo los del ciclo.
 *
 *   - `ispwatch_ref_id`: entidad de ispwatch sobre la que el aviso es idempotente.
 *       · service_activated  → id del cliente (users.id): bienvenida UNA vez por cliente.
 *       · payment_registered → id del pago (payments.id): confirmación una vez por pago.
 *   - `cycle_key` pasa a NULLABLE: los eventos no pertenecen a un ciclo mensual.
 *   - Índice único parcial sobre (tenant, kind, ispwatch_ref_id) para los kinds de
 *     evento, en paralelo al único por ciclo que ya existe para los kinds de billing.
 *
 * En Postgres los NULL son distintos en un índice único, así que el único por
 * ciclo previo [(tenant, kind, customer, cycle_key)] no colisiona con las filas
 * de evento (cycle_key = NULL) — conviven sin tocarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_notification_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('ispwatch_ref_id')->nullable()->after('ispwatch_customer_id');
        });

        // cycle_key deja de ser obligatorio (los eventos no tienen ciclo).
        DB::statement('ALTER TABLE billing_notification_logs ALTER COLUMN cycle_key DROP NOT NULL');

        // Idempotencia de eventos: una fila por (tenant, kind, ref). Parcial para
        // no afectar a las filas de billing (ref NULL).
        DB::statement(
            'CREATE UNIQUE INDEX billing_notif_event_unique_ref
             ON billing_notification_logs (tenant_id, kind, ispwatch_ref_id)
             WHERE ispwatch_ref_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_notif_event_unique_ref');
        DB::statement('ALTER TABLE billing_notification_logs ALTER COLUMN cycle_key SET NOT NULL');

        Schema::table('billing_notification_logs', function (Blueprint $table) {
            $table->dropColumn('ispwatch_ref_id');
        });
    }
};
