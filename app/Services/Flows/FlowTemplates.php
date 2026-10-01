<?php

namespace App\Services\Flows;

use App\Models\BotFlow;
use App\Models\BotSetting;
use App\Models\Tenant;
use App\Services\Bot\IntentDetector;

/**
 * Los puntos de partida de un flujo nuevo. Nadie arma bien un bot desde un
 * lienzo en blanco: se parte de algo que ya funciona y se ajusta.
 *
 *   support → atención de un ISP: saldo (con ispwatch), fallas, pagos, asesor.
 *   legacy  → el bot clásico de ESTE tenant, con SUS textos y SUS switches,
 *             convertido en un flujo editable. Es el flujo semilla de CON-51.
 *   blank   → un saludo y el traspaso a un asesor.
 */
final class FlowTemplates
{
    private const DX = 340;
    private const DY = 190;

    /** @return list<array{key: string, name: string, description: string}> */
    public static function catalog(): array
    {
        return [
            [
                'key'         => 'support',
                'name'        => 'Atención a clientes de un ISP',
                'description' => 'Menú con consultar saldo (lee ispwatch), reportar una falla, medios de pago y hablar con un asesor.',
            ],
            [
                'key'         => 'legacy',
                'name'        => 'Tu bot actual, editable',
                'description' => 'Convierte el bot de Configuración → Bot en un flujo, con tus mismos textos, menú y pasos.',
            ],
            [
                'key'         => 'blank',
                'name'        => 'Empezar casi de cero',
                'description' => 'Un saludo y el traspaso a un asesor. Para armar tu flujo bloque a bloque.',
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return in_array($key, array_column(self::catalog(), 'key'), true);
    }

    /** @return array{nodes: list<array>, edges: list<array>} */
    public function build(string $key, Tenant $tenant): array
    {
        return match ($key) {
            'support' => $this->support(),
            'legacy'  => $this->legacy($tenant),
            default   => $this->blank(),
        };
    }

    private function blank(): array
    {
        $g = new GraphBuilder();

        $g->node('start', 'start', $this->startData(12), 0, 0)
            ->node('saludo', 'message', [
                'text' => "¡{{saludo}}! 👋 Gracias por escribirle a {{empresa.nombre}}.",
            ], self::DX, 0)
            ->node('asesor', 'handoff', [
                'text'   => 'En un momento te atiende una persona del equipo 🙌',
                'assign' => 'auto',
                'note'   => true,
            ], self::DX * 2, 0)
            ->edge('start', 'next', 'saludo')
            ->edge('saludo', 'next', 'asesor');

        return $g->toArray();
    }

    private function support(): array
    {
        $g = new GraphBuilder();

        $g->node('start', 'start', $this->startData(12), 0, 0)
            ->node('menu', 'menu', [
                'text'       => "¡{{saludo}}! 👋 Soy el asistente virtual de {{empresa.nombre}}. ¿En qué te puedo ayudar?",
                'style'      => 'text',
                'append_options' => true,
                'options'    => [
                    ['id' => 'saldo',  'label' => 'Consultar mi saldo',   'keywords' => 'saldo, factura, facturas, cuanto debo, deuda, recibo'],
                    ['id' => 'falla',  'label' => 'Reportar una falla',   'keywords' => 'falla, sin internet, no tengo internet, no hay internet, lento, caido, sin servicio, no funciona, no sirve'],
                    ['id' => 'pagos',  'label' => 'Medios de pago',       'keywords' => 'medios de pago, como pago, donde pago, pagar, nequi, daviplata, consignar, transferencia'],
                    ['id' => 'asesor', 'label' => 'Hablar con un asesor', 'keywords' => 'asesor, humano, persona, agente, hablar con alguien'],
                ],
                'retry_text'  => 'No te entendí 😅. Respóndeme con el número de la opción:',
                'max_retries' => 1,
            ], self::DX, 0)

            // 1 · Saldo: se busca al cliente en ispwatch por su teléfono.
            ->node('buscar', 'ispwatch', [], self::DX * 2, -self::DY * 1.5)
            ->node('debe', 'condition', [
                'match' => 'all',
                'rules' => [['kind' => 'variable', 'variable' => 'cliente.facturas_pendientes', 'operator' => 'greater_than', 'value' => '0']],
            ], self::DX * 3, -self::DY * 2)
            ->node('saldo', 'message', [
                'text' => "{{cliente.primer_nombre}}, tienes {{cliente.facturas_pendientes}} factura(s) pendiente(s) por {{cliente.total_pendiente}}:\n\n{{cliente.detalle_pendientes}}\n\nSi ya pagaste, envíanos el comprobante por aquí.",
            ], self::DX * 4, -self::DY * 2.5)
            ->node('al_dia', 'message', [
                'text' => '¡{{cliente.primer_nombre}}, estás al día! 🙌 No tienes facturas pendientes.',
            ], self::DX * 4, -self::DY * 1.5)
            ->node('fin_saldo', 'end', [], self::DX * 5, -self::DY * 2)
            ->node('no_registrado', 'handoff', [
                'text'   => 'No encontré tu número en nuestro sistema 🤔. Te paso con un asesor para ayudarte.',
                'assign' => 'auto',
                'note'   => true,
            ], self::DX * 3, -self::DY * 0.8)

            // 2 · Falla: se pide el detalle y va al equipo con la nota.
            ->node('falla', 'question', [
                'text'        => 'Lamento el inconveniente 🙏. Cuéntame qué está pasando: ¿desde cuándo? ¿El equipo tiene alguna luz roja?',
                'save_as'     => 'descripcion_falla',
                'validation'  => 'any',
                'max_retries' => 1,
            ], self::DX * 2, self::DY * 0.2)
            ->node('tecnico', 'handoff', [
                'text'   => 'Gracias. Le pasé tu reporte a nuestro equipo técnico 🛠️; en breve te escriben.',
                'assign' => 'auto',
                'note'   => true,
            ], self::DX * 3, self::DY * 0.2)

            // 3 · Pagos: el ISP tiene que escribir sus medios antes de publicar.
            ->node('pagos', 'message', [
                'text' => "Estos son nuestros medios de pago:\n\n• (Escribe aquí tus cuentas, Nequi, puntos de pago…)\n\nCuando pagues, envíanos el comprobante por aquí.",
            ], self::DX * 2, self::DY * 1.2)
            ->node('fin_pagos', 'end', [], self::DX * 3, self::DY * 1.2)

            // 4 · Asesor, y 5 · no entendió.
            ->node('asesor', 'handoff', [
                'text'   => 'Te comunico con un asesor 🙌. En breve alguien del equipo te escribe.',
                'assign' => 'auto',
                'note'   => true,
            ], self::DX * 2, self::DY * 2.2)
            ->node('no_entendio', 'handoff', [
                'text'   => 'No logré entenderte 😅. Te paso con un asesor.',
                'assign' => 'auto',
                'note'   => true,
            ], self::DX * 2, self::DY * 3.2)

            ->edge('start', 'next', 'menu')
            ->edge('menu', 'opt_saldo', 'buscar')
            ->edge('buscar', 'found', 'debe')
            ->edge('buscar', 'not_found', 'no_registrado')
            ->edge('debe', 'true', 'saldo')
            ->edge('debe', 'false', 'al_dia')
            ->edge('saldo', 'next', 'fin_saldo')
            ->edge('al_dia', 'next', 'fin_saldo')
            ->edge('menu', 'opt_falla', 'falla')
            ->edge('falla', 'next', 'tecnico')
            ->edge('menu', 'opt_pagos', 'pagos')
            ->edge('pagos', 'next', 'fin_pagos')
            ->edge('menu', 'opt_asesor', 'asesor')
            ->edge('menu', 'no_match', 'no_entendio');

        return $g->toArray();
    }

    /**
     * El bot clásico de este tenant, traducido bloque a bloque. Respeta sus
     * textos (incluso los personalizados) y sus switches:
     *
     *   - Saludo apagado  → el primer mensaje va directo al traspaso.
     *   - Horario         → Condición de horario; fuera de la franja, traspaso
     *                       silencioso (igual que el bot: no dice nada).
     *   - Ramas apagadas  → no se envía el texto comercial; se pregunta directo.
     *   - Suscriptores / nombre / reintento → cada paso existe solo si estaba
     *                       encendido.
     *
     * Diferencias deliberadas con el bot clásico: las palabras clave se buscan
     * como palabra completa ("ver" ya no cae en "verificar"), y una
     * conversación reabierta vuelve a recibir el saludo.
     */
    private function legacy(Tenant $tenant): array
    {
        $s = $this->legacySettings($tenant);
        $g = new GraphBuilder();

        $g->node('start', 'start', $this->startData(null), 0, 0);
        $entry = 'start';
        $x     = self::DX;

        if ($s['schedule_enabled']) {
            $g->node('horario', 'condition', [
                'match' => 'all',
                'rules' => [[
                    'kind'  => 'schedule',
                    'days'  => array_values(array_map('intval', (array) $s['schedule_days'])),
                    'start' => (string) $s['schedule_start'],
                    'end'   => (string) $s['schedule_end'],
                ]],
            ], $x, 0);
            $g->node('fuera_de_horario', 'handoff', ['text' => '', 'assign' => 'auto', 'note' => false], $x + self::DX, -self::DY * 1.5);
            $g->edge('start', 'next', 'horario');

            $outside = $s['schedule_mode'] === 'outside';
            // Modo "dentro": el bot atiende en la franja. Modo "fuera": al revés.
            $g->edge('horario', $outside ? 'true' : 'false', 'fuera_de_horario');
            $entry = 'horario:' . ($outside ? 'false' : 'true');
            $x += self::DX;
        }

        [$entryNode, $entryHandle] = str_contains($entry, ':') ? explode(':', $entry) : [$entry, 'next'];

        $handoff = fn (string $id, string $text, float $px, float $py) => $g->node($id, 'handoff', [
            'text'   => $text,
            'assign' => 'auto',
            'note'   => true,
        ], $px, $py);

        if (! $s['step_greeting_enabled']) {
            $handoff('traspaso', (string) $s['msg_handoff'], $x, 0);
            $g->edge($entryNode, $entryHandle, 'traspaso');

            return $g->toArray();
        }

        $keywords = app(IntentDetector::class)->keywordGroups();
        $labels   = [
            'info'  => 'Conocer ISPWatch',
            'socio' => 'Programa Socio Fundador',
            'demo'  => 'Ver una demo',
            'price' => 'Precios y planes',
            'agent' => 'Hablar con un asesor',
        ];

        $g->node('menu', 'menu', [
            // El saludo del bot ya trae sus opciones numeradas: se envía tal cual.
            'text'           => (string) $s['msg_greeting'],
            'style'          => 'text',
            'append_options' => false,
            'options'        => array_map(fn (string $key) => [
                'id'       => $key,
                'label'    => $labels[$key],
                'keywords' => implode(', ', $keywords[$key] ?? []),
            ], array_keys($labels)),
            'retry_text'     => (string) $s['msg_fallback_1'],
            'max_retries'    => $s['step_fallback_enabled'] ? 1 : 0,
        ], $x, 0);
        $g->edge($entryNode, $entryHandle, 'menu');

        $x += self::DX;

        // Después de la rama: nombre (si hace falta) y traspaso.
        $handoff('traspaso', (string) $s['msg_handoff'], $x + self::DX * 3, 0);
        $after = 'traspaso';

        if ($s['step_qualify_name_enabled']) {
            $g->node('tiene_nombre', 'condition', [
                'match' => 'all',
                'rules' => [['kind' => 'variable', 'variable' => 'contacto.nombre', 'operator' => 'is_empty', 'value' => '']],
            ], $x + self::DX * 1, 0);
            $g->node('nombre', 'question', [
                'text'            => (string) $s['msg_ask_name'],
                'save_as'         => 'nombre',
                'validation'      => 'any',
                'save_to_contact' => 'name',
                'max_retries'     => 1,
            ], $x + self::DX * 2, -self::DY * 0.6);
            $g->edge('tiene_nombre', 'true', 'nombre');
            $g->edge('tiene_nombre', 'false', 'traspaso');
            $g->edge('nombre', 'next', 'traspaso');
            $after = 'tiene_nombre';
        }

        $branches = ['info' => 'msg_info', 'socio' => 'msg_socio', 'demo' => 'msg_demo', 'price' => 'msg_price'];
        $row      = -1.5;

        if (! $s['step_branches_enabled'] && $s['step_qualify_subscribers_enabled']) {
            // Sin discurso comercial: una sola pregunta de suscriptores para todas las ramas.
            $g->node('suscriptores', 'question', [
                'text'        => (string) $s['msg_ask_subscribers'],
                'save_as'     => 'suscriptores',
                'validation'  => 'any',
                'max_retries' => 1,
            ], $x, -self::DY);
            $g->edge('suscriptores', 'next', $after);
        }

        foreach ($branches as $key => $field) {
            $target = $after;

            if ($s['step_branches_enabled']) {
                // Los textos de rama ya terminan preguntando por los
                // suscriptores: con ese paso encendido, la rama ES la pregunta.
                $type = $s['step_qualify_subscribers_enabled'] ? 'question' : 'message';
                $data = $type === 'question'
                    ? ['text' => (string) $s[$field], 'save_as' => 'suscriptores', 'validation' => 'any', 'max_retries' => 1]
                    : ['text' => (string) $s[$field]];

                $g->node("rama_{$key}", $type, $data, $x, self::DY * $row);
                $g->edge("rama_{$key}", 'next', $after);
                $target = "rama_{$key}";
            } elseif ($s['step_qualify_subscribers_enabled']) {
                $target = 'suscriptores';
            }

            $g->edge('menu', "opt_{$key}", $target);
            $row += 1;
        }

        $handoff('asesor', (string) $s['msg_handoff'], $x, self::DY * 2.8);
        $handoff('no_entendio', (string) $s['msg_fallback_2'], $x, self::DY * 3.8);
        $g->edge('menu', 'opt_agent', 'asesor');
        $g->edge('menu', 'no_match', 'no_entendio');

        return $g->toArray();
    }

    /** La fila del bot con los defaults donde esté vacía (misma regla que BotSettingsController). */
    private function legacySettings(Tenant $tenant): array
    {
        $row = BotSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        return array_merge(
            BotSetting::defaults(),
            $row ? array_filter($row->toArray(), fn ($v) => ! is_null($v)) : [],
        );
    }

    private function startData(?int $idleHours): array
    {
        return [
            'trigger'  => [
                'type'       => BotFlow::TRIGGER_CONVERSATION_START,
                'idle_hours' => $idleHours,
            ],
            'timezone' => (string) config('flows.default_timezone', 'America/Bogota'),
        ];
    }
}
