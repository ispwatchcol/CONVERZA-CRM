<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre automático de chats por falta de respuesta del cliente.
 *
 * Cuando el equipo ya respondió y el cliente no vuelve a escribir, el chat queda
 * abierto para siempre y ensucia la bandeja: nadie sabe si sigue pendiente. Con
 * esto, pasadas N horas sin respuesta el chat se cierra solo (en silencio, sin
 * mandarle nada al cliente por WhatsApp).
 *
 * Default OFF y 2 h: no cambia el comportamiento de ningún tenant hasta que su
 * admin lo active en Configuración → Cierre automático.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('auto_close_enabled')->default(false)->after('auto_assign_enabled');
            $table->unsignedSmallInteger('auto_close_hours')->default(2)->after('auto_close_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['auto_close_enabled', 'auto_close_hours']);
        });
    }
};
