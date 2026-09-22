<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // El aviso interno (CON-82): cuando un ISP abre un ticket por el portal, nos
    // llega un correo. Necesita un `kind` nuevo —para que la idempotencia por
    // (ticket_event_id, kind) lo trate como un aviso aparte del acuse al cliente—
    // y un `channel` nuevo, porque este no sale por WhatsApp.
    public function up(): void
    {
        DB::statement('ALTER TABLE ticket_notification_logs DROP CONSTRAINT ticket_notification_logs_kind_check');
        DB::statement("ALTER TABLE ticket_notification_logs ADD CONSTRAINT ticket_notification_logs_kind_check CHECK (kind IN ('acuse', 'respuesta', 'resuelto', 'interno_nuevo'))");

        DB::statement('ALTER TABLE ticket_notification_logs DROP CONSTRAINT ticket_notification_logs_channel_check');
        DB::statement("ALTER TABLE ticket_notification_logs ADD CONSTRAINT ticket_notification_logs_channel_check CHECK (channel IN ('whatsapp_text', 'whatsapp_template', 'email', 'none'))");
    }

    public function down(): void
    {
        // Las filas de los avisos internos quedarían fuera de los constraints
        // viejos, así que se borran antes de apretarlos. Es bitácora: no se pierde
        // nada que no se pueda volver a deducir de los tickets.
        DB::table('ticket_notification_logs')->where('kind', 'interno_nuevo')->delete();
        DB::table('ticket_notification_logs')->where('channel', 'email')->update(['channel' => 'none']);

        DB::statement('ALTER TABLE ticket_notification_logs DROP CONSTRAINT ticket_notification_logs_kind_check');
        DB::statement("ALTER TABLE ticket_notification_logs ADD CONSTRAINT ticket_notification_logs_kind_check CHECK (kind IN ('acuse', 'respuesta', 'resuelto'))");

        DB::statement('ALTER TABLE ticket_notification_logs DROP CONSTRAINT ticket_notification_logs_channel_check');
        DB::statement("ALTER TABLE ticket_notification_logs ADD CONSTRAINT ticket_notification_logs_channel_check CHECK (channel IN ('whatsapp_text', 'whatsapp_template', 'none'))");
    }
};
