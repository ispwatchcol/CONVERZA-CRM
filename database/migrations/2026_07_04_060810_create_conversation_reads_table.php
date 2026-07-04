<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca por agente de "hasta qué momento leyó" una conversación — no existía
 * ningún concepto de esto antes (el `status=read` de messages es el check
 * azul de WhatsApp: el CLIENTE leyó lo que enviamos, no al revés). Sin esta
 * tabla, "no leído" solo podía vivir en memoria del navegador y se perdía en
 * cada refresh / no se compartía entre agentes o pestañas.
 *
 * Una fila por (conversation_id, staff_member_id): se actualiza (upsert) cada
 * vez que ese agente tiene la conversación abierta. "No leído" se calcula
 * comparando mensajes entrantes (status='received') contra last_read_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'staff_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
    }
};
