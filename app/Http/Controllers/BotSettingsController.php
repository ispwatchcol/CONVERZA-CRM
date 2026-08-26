<?php

namespace App\Http\Controllers;

use App\Models\BotLog;
use App\Models\BotSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BotSettingsController extends Controller
{
    /**
     * Zonas horarias ofrecidas para el horario del bot. Lista corta y explícita
     * (la app corre en UTC y los ISPs son de LatAm) para que el <select> del
     * frontend y la validación compartan una sola fuente de verdad.
     */
    public const TIMEZONES = [
        'America/Bogota',
        'America/Lima',
        'America/Guayaquil',
        'America/Panama',
        'America/Mexico_City',
        'America/Guatemala',
        'America/Costa_Rica',
        'America/Santo_Domingo',
        'America/Caracas',
        'America/La_Paz',
        'America/Asuncion',
        'America/Santiago',
        'America/Argentina/Buenos_Aires',
        'America/Montevideo',
        'America/Sao_Paulo',
        'UTC',
    ];

    public function index()
    {
        $tenant   = app('tenant');
        $settings = BotSetting::where('tenant_id', $tenant->id)->first();
        $defaults = BotSetting::defaults();

        // Si no existe la fila del tenant, enviamos los defaults para que el
        // formulario llegue pre-relleno y el admin solo tenga que activar el toggle.
        //
        // Los nulls se descartan antes del merge: las columnas añadidas después
        // (msg_ask_*, schedule_days) están vacías en las filas creadas antes de
        // la migración y, sin este filtro, pisarían el default con null y el
        // formulario llegaría en blanco.
        $botSettings = $settings
            ? array_merge($defaults, array_filter($settings->toArray(), fn ($v) => ! is_null($v)))
            : array_merge($defaults, ['id' => null, 'tenant_id' => $tenant->id]);

        $recentLogs = BotLog::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'conversation_id', 'intent_detected', 'escalated', 'context_data', 'created_at'])
            ->map(fn ($log) => [
                'id'              => $log->id,
                'conversation_id' => $log->conversation_id,
                'intent_detected' => $log->intent_detected,
                'escalated'       => (bool) $log->escalated,
                'context_data'    => $log->context_data ? json_decode($log->context_data, true) : null,
                'created_at'      => $log->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Settings/Bot', [
            'botSettings' => $botSettings,
            'botState'    => self::stateOf($settings),
            'timezones'   => self::TIMEZONES,
            'recentLogs'  => $recentLogs,
        ]);
    }

    public function update(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'bot_enabled' => ['required', 'boolean'],

            'schedule_enabled'  => ['required', 'boolean'],
            'schedule_mode'     => ['required', Rule::in(['inside', 'outside'])],
            'schedule_timezone' => ['required', Rule::in(self::TIMEZONES)],
            'schedule_days'     => ['required_if:schedule_enabled,true', 'array'],
            'schedule_days.*'   => ['integer', 'between:1,7'],
            'schedule_start'    => ['required', 'date_format:H:i'],
            'schedule_end'      => ['required', 'date_format:H:i'],

            'step_greeting_enabled'            => ['required', 'boolean'],
            'step_branches_enabled'            => ['required', 'boolean'],
            'step_qualify_subscribers_enabled' => ['required', 'boolean'],
            'step_qualify_name_enabled'        => ['required', 'boolean'],
            'step_fallback_enabled'            => ['required', 'boolean'],

            'msg_greeting'        => ['required', 'string', 'max:2000'],
            'msg_info'            => ['required', 'string', 'max:2000'],
            'msg_socio'           => ['required', 'string', 'max:2000'],
            'msg_demo'            => ['required', 'string', 'max:2000'],
            'msg_price'           => ['required', 'string', 'max:2000'],
            'msg_ask_subscribers' => ['required', 'string', 'max:2000'],
            'msg_ask_name'        => ['required', 'string', 'max:2000'],
            'msg_handoff'         => ['required', 'string', 'max:2000'],
            'msg_fallback_1'      => ['required', 'string', 'max:2000'],
            'msg_fallback_2'      => ['required', 'string', 'max:2000'],
        ], [
            'schedule_days.required_if' => 'Elige al menos un día para el horario del bot.',
            'schedule_start.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'schedule_end.date_format'   => 'La hora de fin debe tener el formato HH:MM.',
        ]);

        // Días normalizados: enteros únicos y ordenados, para que el resumen y
        // la comparación de respondsAt() sean estables.
        if (isset($data['schedule_days'])) {
            $days = array_values(array_unique(array_map('intval', $data['schedule_days'])));
            sort($days);
            $data['schedule_days'] = $days;
        }

        BotSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $data,
        );

        return back()->with('success', 'Configuración del bot guardada correctamente.');
    }

    /**
     * Interruptor rápido desde la tarjeta de Configuración.
     *
     * Existe aparte de update() porque aquel exige todos los campos como
     * required: un PUT que solo trae el flag no pasaría la validación.
     * forTenant() crea la fila con los defaults si aún no existe, porque las
     * columnas msg_* son NOT NULL sin default.
     */
    public function toggle(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'bot_enabled' => ['required', 'boolean'],
        ]);

        BotSetting::forTenant($tenant->id)->update([
            'bot_enabled' => (bool) $data['bot_enabled'],
        ]);

        return back()->with('success', $data['bot_enabled']
            ? 'Bot activado.'
            : 'Bot desactivado.');
    }

    /**
     * Estado real del bot, que no es solo el flag: con el horario activo el bot
     * puede estar encendido pero mudo por estar fuera de la franja.
     *
     * @return 'off'|'active'|'out_of_schedule'
     */
    public static function stateOf(?BotSetting $settings): string
    {
        if (! $settings?->bot_enabled) {
            return 'off';
        }

        return $settings->respondsAt() ? 'active' : 'out_of_schedule';
    }
}
