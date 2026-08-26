<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horario de atención y switches por paso para el bot.
     *
     * Todos los defaults reproducen el comportamiento anterior al cambio:
     * horario apagado y los cinco pasos encendidos. Así ningún tenant nota
     * diferencia al desplegar; el control es estrictamente opt-in.
     */
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            // ── Horario ──────────────────────────────────────────────────────
            $table->boolean('schedule_enabled')->default(false)->after('bot_enabled');

            // 'inside'  → el bot responde DENTRO de la franja
            // 'outside' → el bot responde SOLO FUERA de la franja (guardia nocturna)
            $table->string('schedule_mode', 10)->default('inside')->after('schedule_enabled');

            // La app corre en UTC (config/app.php). La franja que escribe el admin
            // es hora de pared local, así que la zona viaja con la configuración.
            $table->string('schedule_timezone', 64)->default('America/Bogota')->after('schedule_mode');

            // Días ISO-8601: 1 = lunes … 7 = domingo.
            $table->json('schedule_days')->nullable()->after('schedule_timezone');

            // 'HH:MM'. String y no `time` por portabilidad MySQL/Postgres y porque
            // es exactamente lo que emite <input type="time">.
            $table->string('schedule_start', 5)->default('08:00')->after('schedule_days');
            $table->string('schedule_end', 5)->default('18:00')->after('schedule_start');

            // ── Pasos del flujo ──────────────────────────────────────────────
            // Apagar un paso significa saltarlo y avanzar al siguiente habilitado;
            // si no queda ninguno, el bot hace handoff.
            $table->boolean('step_greeting_enabled')->default(true)->after('schedule_end');
            $table->boolean('step_branches_enabled')->default(true)->after('step_greeting_enabled');
            $table->boolean('step_qualify_subscribers_enabled')->default(true)->after('step_branches_enabled');
            $table->boolean('step_qualify_name_enabled')->default(true)->after('step_qualify_subscribers_enabled');
            $table->boolean('step_fallback_enabled')->default(true)->after('step_qualify_name_enabled');

            // ── Textos que faltaban ──────────────────────────────────────────
            // Nullable a propósito: las filas existentes no tienen valor y el
            // código cae a BotSetting::defaults() cuando vienen vacías.
            $table->text('msg_ask_subscribers')->nullable()->after('msg_price');
            $table->text('msg_ask_name')->nullable()->after('msg_ask_subscribers');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'schedule_enabled',
                'schedule_mode',
                'schedule_timezone',
                'schedule_days',
                'schedule_start',
                'schedule_end',
                'step_greeting_enabled',
                'step_branches_enabled',
                'step_qualify_subscribers_enabled',
                'step_qualify_name_enabled',
                'step_fallback_enabled',
                'msg_ask_subscribers',
                'msg_ask_name',
            ]);
        });
    }
};
