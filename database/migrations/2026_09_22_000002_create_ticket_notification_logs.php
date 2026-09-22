<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Avisos al ISP sobre SUS tickets (CON-57). Dos cosas:
    //
    // 1. El opt-in por cuenta. Nace apagado a propósito: mandarle WhatsApp a un
    //    cliente que no lo pidió es exactamente cómo se queman los números.
    // 2. La bitácora, que además es la idempotencia: un reintento del job no
    //    puede mandar el mismo aviso dos veces.
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('support_notify_enabled')->default(false);
            // Si está vacío se usa `contact_phone`, que ya existe en la ficha. La
            // columna aparte permite avisarle a otra persona (el técnico de turno)
            // sin tocar el contacto comercial de la cuenta.
            $table->string('support_notify_phone', 30)->nullable();
        });

        Schema::create('ticket_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // El evento que disparó el aviso. Es la llave de idempotencia: un
            // evento genera como mucho un aviso de cada tipo, se reintente el job
            // las veces que se reintente.
            $table->foreignId('ticket_event_id')->nullable()->constrained('ticket_events')->cascadeOnDelete();

            $table->enum('kind', ['acuse', 'respuesta', 'resuelto']);
            $table->enum('status', ['sent', 'skipped', 'failed']);
            $table->enum('channel', ['whatsapp_text', 'whatsapp_template', 'none'])->default('none');

            $table->string('phone', 30)->nullable();
            $table->string('template')->nullable();
            $table->string('wa_message_id')->nullable();
            // Por qué no salió: 'apagado', 'sin_telefono', 'sin_plantilla',
            // 'fuera_de_ventana', o el error de Meta.
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['ticket_event_id', 'kind']);
            $table->index(['support_ticket_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_notification_logs');

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['support_notify_enabled', 'support_notify_phone']);
        });
    }
};
