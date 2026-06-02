<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migra la configuración existente de avisos (las columnas planas
 * tenants.wa_invoice_template / wa_reminder_template) a la tabla genérica
 * tenant_notification_routes, sin perder lo que cada tenant ya tenía elegido.
 *
 * Las columnas viejas NO se eliminan aquí (quedan latentes por seguridad); el
 * código nuevo deja de leerlas.
 */
return new class extends Migration
{
    /** Columna vieja → evento del catálogo. */
    private array $map = [
        'wa_invoice_template'  => 'invoice_created',
        'wa_reminder_template' => 'payment_reminder',
    ];

    public function up(): void
    {
        $tenants = DB::table('tenants')->get(['id', 'wa_invoice_template', 'wa_reminder_template']);

        foreach ($tenants as $tenant) {
            foreach ($this->map as $column => $eventKey) {
                $templateName = $tenant->{$column} ?? null;
                if (blank($templateName)) {
                    continue;
                }

                // Resolver la plantilla por nombre dentro del mismo tenant.
                $templateId = DB::table('templates')
                    ->where('tenant_id', $tenant->id)
                    ->where('name', $templateName)
                    ->value('id');

                DB::table('tenant_notification_routes')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'event_key' => $eventKey],
                    [
                        'template_id' => $templateId,
                        'enabled'     => $templateId !== null,
                        'updated_at'  => now(),
                        'created_at'  => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        // No se revierte: las columnas originales siguen intactas como respaldo.
    }
};
