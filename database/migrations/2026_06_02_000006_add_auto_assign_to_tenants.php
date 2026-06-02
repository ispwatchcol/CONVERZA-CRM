<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interruptor por tenant de la AUTO-ASIGNACIÓN de conversaciones entrantes.
 * Cuando está en true, al llegar un mensaje a una conversación abierta sin
 * dueño, se asigna automáticamente al agente con MENOS conversaciones abiertas
 * ("al menos ocupado"). Default false: nadie se sorprende, se activa a propósito
 * desde Configuración → Asignación automática.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('auto_assign_enabled')->default(false)->after('billing_notify_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('auto_assign_enabled');
        });
    }
};
