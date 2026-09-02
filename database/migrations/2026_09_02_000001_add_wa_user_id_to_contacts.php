<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad del contacto cuando Meta no manda el teléfono.
 *
 * Desde el despliegue de los *WhatsApp usernames*, un cliente puede ocultar su
 * número al escribirle a un negocio. En ese caso el webhook no trae `from`, sino
 * un Business-Scoped User ID (BSUID) en `from_user_id`, con forma `CO.<digitos>`.
 *
 * Hasta ahora el teléfono ERA la identidad —`contacts.phone` NOT NULL con único
 * (tenant_id, phone)— así que esos mensajes no tenían dónde guardarse y el job
 * los descartaba. Se perdieron 20 mensajes de 5 clientes en dos semanas (CON-68).
 *
 * El BSUID es estable por par (usuario, negocio), así que sirve de llave por sí
 * solo. `phone` pasa a ser nulable: en Postgres el índice único existente admite
 * varios NULL, así que los contactos sin teléfono no chocan entre sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('wa_user_id')->nullable()->after('phone');
            // El username es solo para mostrar: es lo único legible por humanos
            // que queda cuando no hay teléfono, y evita que el asesor vea un
            // "CO.1124418266822967" como nombre del chat.
            $table->string('wa_username')->nullable()->after('wa_user_id');
        });

        // Un contacto por BSUID y tenant. Los NULL no cuentan para el único, así
        // que los contactos de siempre (con teléfono y sin BSUID) no se ven afectados.
        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'wa_user_id'], 'contacts_tenant_id_wa_user_id_unique');
        });

        // `phone` deja de ser obligatorio. sqlite (tests) no soporta ALTER COLUMN
        // y allí las tablas se crean de cero, así que solo aplica en Postgres.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contacts ALTER COLUMN phone DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // No se restaura el NOT NULL de `phone`: si ya hay contactos sin teléfono,
        // volver atrás fallaría a mitad. Hay que decidirlo a mano.
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_tenant_id_wa_user_id_unique');
            $table->dropColumn(['wa_user_id', 'wa_username']);
        });
    }
};
