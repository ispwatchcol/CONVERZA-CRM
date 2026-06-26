<?php

namespace App\Http\Controllers;

use App\Models\BotLog;
use App\Models\BotSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BotSettingsController extends Controller
{
    public function index()
    {
        $tenant   = app('tenant');
        $settings = BotSetting::where('tenant_id', $tenant->id)->first();
        $defaults = BotSetting::defaults();

        // Si no existe la fila del tenant, enviamos los defaults para que el
        // formulario llegue pre-relleno y el admin solo tenga que activar el toggle.
        $botSettings = $settings
            ? array_merge($defaults, $settings->toArray())
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
            'recentLogs'  => $recentLogs,
        ]);
    }

    public function update(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'bot_enabled'    => ['required', 'boolean'],
            'msg_greeting'   => ['required', 'string', 'max:2000'],
            'msg_info'       => ['required', 'string', 'max:2000'],
            'msg_socio'      => ['required', 'string', 'max:2000'],
            'msg_demo'       => ['required', 'string', 'max:2000'],
            'msg_price'      => ['required', 'string', 'max:2000'],
            'msg_handoff'    => ['required', 'string', 'max:2000'],
            'msg_fallback_1' => ['required', 'string', 'max:2000'],
            'msg_fallback_2' => ['required', 'string', 'max:2000'],
        ]);

        BotSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $data,
        );

        return back()->with('success', 'Configuración del bot guardada correctamente.');
    }
}
