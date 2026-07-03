<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amplía el índice único parcial de los avisos por evento para incluir
 * `ispwatch_customer_id`. Necesario para la FALLA MASIVA (router_outage_*): un
 * mismo evento de outage (ispwatch_ref_id = router_outage_events.id) se notifica
 * a MUCHOS clientes, así que la unicidad debe ser por (tenant, kind, outage, cliente),
 * no solo por (tenant, kind, outage).
 *
 * Compatible con bienvenida/pago: ahí ispwatch_ref_id ya es único por cliente
 * (customer_id / payment_id), así que añadir customer_id a la llave no cambia su
 * semántica ("una vez por cliente" / "una vez por pago").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_notif_event_unique_ref');
        DB::statement(
            'CREATE UNIQUE INDEX billing_notif_event_unique_ref
             ON billing_notification_logs (tenant_id, kind, ispwatch_ref_id, ispwatch_customer_id)
             WHERE ispwatch_ref_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS billing_notif_event_unique_ref');
        DB::statement(
            'CREATE UNIQUE INDEX billing_notif_event_unique_ref
             ON billing_notification_logs (tenant_id, kind, ispwatch_ref_id)
             WHERE ispwatch_ref_id IS NOT NULL'
        );
    }
};
