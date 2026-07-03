<?php

namespace App\Services\Notifications;

use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Catálogo de "eventos" (propósitos) de los avisos automáticos y las variables
 * que cada uno expone a las plantillas. Es la ÚNICA fuente de verdad, leída por:
 *
 *   - El editor de plantillas (paleta de variables que el cliente puede insertar).
 *   - Settings (lista de eventos para asignarles una plantilla).
 *   - El comando whatsapp:billing-notify (resuelve los valores reales al enviar).
 *
 * Las variables son NOMBRADAS ({{nombre_cliente}}, {{monto}}, …) — coinciden con
 * los named parameters de Meta, así el cliente nunca ve un {{1}} pelado y el orden
 * en que las escriba no importa.
 *
 * La metadata (label/description/sample) vive en {@see events()}; los valores reales
 * se calculan en {@see resolveValues()} para no meter closures en arrays (config:cache
 * no puede serializar closures, y además así son testeables).
 *
 * Convención: la CLAVE de cada evento coincide con BillingNotificationLog::KIND_*.
 */
class EventCatalog
{
    /**
     * Definición de los eventos disponibles y sus variables.
     *
     * `auto` = el evento se dispara solo, en una fecha (lo manejan los avisos
     * automáticos y aparece en Settings → Avisos). Un evento no-auto (ej. "general")
     * es solo un PROPÓSITO para armar plantillas que se envían a mano.
     *
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     trigger: string,
     *     auto: bool,
     *     variables: array<string, array{label: string, description: string, sample: string}>
     * }>
     */
    public static function events(): array
    {
        return [
            'general' => [
                'label'       => 'General (datos del cliente)',
                'description' => 'Plantilla de propósito libre que puedes enviar manualmente; rellena datos del cliente, tu empresa y la fecha.',
                'trigger'     => 'Manual (no se envía sola).',
                'auto'        => false,
                'variables'   => [
                    // ── Cliente (de ispwatch) ──────────────────────────────────
                    'nombre_cliente'  => ['label' => 'Nombre del cliente',  'description' => 'Nombre completo del cliente.',                 'sample' => 'Juan Pérez'],
                    'primer_nombre'   => ['label' => 'Primer nombre',       'description' => 'Solo el primer nombre del cliente.',           'sample' => 'Juan'],
                    'correo'          => ['label' => 'Correo',              'description' => 'Email registrado del cliente.',                'sample' => 'juan@correo.com'],
                    'telefono'        => ['label' => 'Teléfono',            'description' => 'Teléfono registrado del cliente.',             'sample' => '3001234567'],
                    'cedula'          => ['label' => 'Cédula',              'description' => 'Cédula del cliente.',                          'sample' => '12345678'],
                    'documento'       => ['label' => 'Documento',           'description' => 'Número de documento del cliente.',             'sample' => 'CC 12345678'],
                    'usuario_pppoe'   => ['label' => 'Usuario PPPoE',       'description' => 'Usuario de conexión del cliente.',             'sample' => 'juanperez01'],
                    'estado_servicio' => ['label' => 'Estado del servicio', 'description' => 'Estado del servicio (activo, suspendido…).',    'sample' => 'Activo'],
                    'saldo_favor'     => ['label' => 'Saldo a favor',       'description' => 'Saldo a favor del cliente.',                   'sample' => '$0'],
                    'direccion'       => ['label' => 'Dirección',           'description' => 'Dirección registrada del cliente.',            'sample' => 'Cra 10 # 20-30'],
                    'ip_cliente'      => ['label' => 'IP del cliente',      'description' => 'IP asignada al cliente.',                      'sample' => '10.0.0.25'],
                    // ── Empresa / agente ───────────────────────────────────────
                    'empresa'         => ['label' => 'Tu empresa',          'description' => 'Nombre comercial de tu workspace.',            'sample' => 'Mi ISP'],
                    'agente'          => ['label' => 'Agente',              'description' => 'Nombre del agente que envía (en envío manual).', 'sample' => 'María (Soporte)'],
                    // ── Fecha / hora ───────────────────────────────────────────
                    'saludo'          => ['label' => 'Saludo según la hora', 'description' => 'Buenos días / Buenas tardes / Buenas noches.', 'sample' => 'Buenos días'],
                    'fecha_actual'    => ['label' => 'Fecha de hoy',        'description' => 'Fecha actual (d/m/Y).',                        'sample' => '02/06/2026'],
                    'hora_actual'     => ['label' => 'Hora actual',         'description' => 'Hora actual (HH:mm).',                         'sample' => '14:30'],
                    'dia_semana'      => ['label' => 'Día de la semana',     'description' => 'Día actual en texto.',                         'sample' => 'lunes'],
                    'mes_actual'      => ['label' => 'Mes actual',          'description' => 'Mes actual en texto.',                         'sample' => 'junio'],
                    'anio_actual'     => ['label' => 'Año actual',          'description' => 'Año actual.',                                  'sample' => '2026'],
                    'fecha_larga'     => ['label' => 'Fecha larga',         'description' => 'Fecha actual en texto largo.',                 'sample' => '2 de junio de 2026'],
                ],
            ],

            'invoice_created' => [
                'label'       => 'Factura generada',
                'description' => 'Avisa al cliente que su factura del ciclo ya fue generada.',
                'trigger'     => 'El día que el router crea la factura (campo create_invoice del billing).',
                'auto'        => true,
                'variables'   => [
                    'nombre_cliente'    => ['label' => 'Nombre del cliente',    'description' => 'Nombre del cliente en ispwatch.',        'sample' => 'Juan Pérez'],
                    'monto'             => ['label' => 'Monto de la factura',   'description' => 'Total de la factura del ciclo.',          'sample' => '$45.000'],
                    'mes_facturado'     => ['label' => 'Mes facturado',         'description' => 'Mes al que corresponde la factura, en texto.', 'sample' => 'julio'],
                    'numero_factura'    => ['label' => 'Número de factura',     'description' => 'Consecutivo de la factura.',              'sample' => 'FAC-1234'],
                    'fecha_vencimiento' => ['label' => 'Fecha de vencimiento',  'description' => 'Fecha límite de pago (formato d/m/Y).',   'sample' => '15/06/2026'],
                    'empresa'           => ['label' => 'Tu empresa',            'description' => 'Nombre comercial de tu workspace.',       'sample' => 'Mi ISP'],
                ],
            ],

            'payment_reminder' => [
                'label'       => 'Recordatorio de pago',
                'description' => 'Recuerda al cliente que tiene un saldo pendiente por pagar.',
                'trigger'     => 'El día de recordatorio del router (campo payment_reminder del billing), si está habilitado.',
                'auto'        => true,
                'variables'   => [
                    'nombre_cliente'    => ['label' => 'Nombre del cliente',    'description' => 'Nombre del cliente en ispwatch.',        'sample' => 'Juan Pérez'],
                    'saldo'             => ['label' => 'Saldo pendiente',       'description' => 'Saldo aún por pagar de la factura.',      'sample' => '$45.000'],
                    'mes_facturado'     => ['label' => 'Mes facturado',         'description' => 'Mes al que corresponde la factura, en texto.', 'sample' => 'julio'],
                    'numero_factura'    => ['label' => 'Número de factura',     'description' => 'Consecutivo de la factura.',              'sample' => 'FAC-1234'],
                    'fecha_vencimiento' => ['label' => 'Fecha de vencimiento',  'description' => 'Fecha límite de pago (formato d/m/Y).',   'sample' => '15/06/2026'],
                    'empresa'           => ['label' => 'Tu empresa',            'description' => 'Nombre comercial de tu workspace.',       'sample' => 'Mi ISP'],
                ],
            ],

            'service_suspension' => [
                'label'       => 'Aviso de suspensión (corte por mora)',
                'description' => 'Avisa al cliente con saldo pendiente que su servicio será suspendido por mora. Distinto del recordatorio: se envía el día de CORTE del router, no el de recordatorio.',
                'trigger'     => 'El día de corte del router (campo cut_day del billing). Solo a clientes con saldo pendiente.',
                'auto'        => true,
                'variables'   => [
                    'nombre_cliente'    => ['label' => 'Nombre del cliente',    'description' => 'Nombre del cliente en ispwatch.',        'sample' => 'Juan Pérez'],
                    'saldo'             => ['label' => 'Saldo pendiente',       'description' => 'Saldo aún por pagar de la factura.',      'sample' => '$45.000'],
                    'mes_facturado'     => ['label' => 'Mes facturado',         'description' => 'Mes al que corresponde la factura, en texto.', 'sample' => 'julio'],
                    'numero_factura'    => ['label' => 'Número de factura',     'description' => 'Consecutivo de la factura.',              'sample' => 'FAC-1234'],
                    'fecha_vencimiento' => ['label' => 'Fecha de vencimiento',  'description' => 'Fecha límite de pago (formato d/m/Y).',   'sample' => '15/06/2026'],
                    'fecha_corte'       => ['label' => 'Fecha de corte',        'description' => 'Fecha en que se suspende el servicio (hoy).', 'sample' => '30/06/2026'],
                    'empresa'           => ['label' => 'Tu empresa',            'description' => 'Nombre comercial de tu workspace.',       'sample' => 'Mi ISP'],
                ],
            ],

            'service_activated' => [
                'label'       => 'Bienvenida (servicio activado)',
                'description' => 'Da la bienvenida al cliente cuando se le activa el servicio en ispwatch. Se envía UNA sola vez por cliente y SOLO en altas manuales: las cargas masivas se omiten aunque el aviso esté activo.',
                'trigger'     => 'Cuando se activa el servicio de un cliente creado manualmente (no en carga masiva ni al editar el servicio).',
                'auto'        => true,
                'variables'   => [
                    'nombre_cliente' => ['label' => 'Nombre del cliente', 'description' => 'Nombre completo del cliente.',        'sample' => 'Juan Pérez'],
                    'primer_nombre'  => ['label' => 'Primer nombre',      'description' => 'Solo el primer nombre del cliente.',  'sample' => 'Juan'],
                    'plan'           => ['label' => 'Plan contratado',    'description' => 'Nombre del plan de servicio activado.', 'sample' => '10 MEGAS'],
                    'usuario_pppoe'  => ['label' => 'Usuario PPPoE',      'description' => 'Usuario de conexión del cliente.',    'sample' => 'juanperez01'],
                    'direccion'      => ['label' => 'Dirección',          'description' => 'Dirección registrada del cliente.',   'sample' => 'Cra 10 # 20-30'],
                    'empresa'        => ['label' => 'Tu empresa',         'description' => 'Nombre comercial de tu workspace.',   'sample' => 'Mi ISP'],
                    'saludo'         => ['label' => 'Saludo según la hora', 'description' => 'Buenos días / Buenas tardes / Buenas noches.', 'sample' => 'Buenos días'],
                    'fecha_actual'   => ['label' => 'Fecha de hoy',       'description' => 'Fecha actual (d/m/Y).',               'sample' => '03/07/2026'],
                ],
            ],

            'payment_registered' => [
                'label'       => 'Pago registrado',
                'description' => 'Confirma al cliente que su pago quedó registrado en ispwatch.',
                'trigger'     => 'Cuando se registra un pago del cliente en ispwatch.',
                'auto'        => true,
                'variables'   => [
                    'nombre_cliente' => ['label' => 'Nombre del cliente', 'description' => 'Nombre completo del cliente.',        'sample' => 'Juan Pérez'],
                    'primer_nombre'  => ['label' => 'Primer nombre',      'description' => 'Solo el primer nombre del cliente.',  'sample' => 'Juan'],
                    'monto'          => ['label' => 'Monto del pago',     'description' => 'Valor del pago registrado.',          'sample' => '$45.000'],
                    'metodo'         => ['label' => 'Método de pago',     'description' => 'Medio con que se registró el pago.',  'sample' => 'Efectivo'],
                    'referencia'     => ['label' => 'Referencia',         'description' => 'Referencia o comprobante del pago.',  'sample' => 'REF-0099'],
                    'fecha_pago'     => ['label' => 'Fecha del pago',     'description' => 'Fecha en que se registró el pago (d/m/Y).', 'sample' => '03/07/2026'],
                    'saldo_restante' => ['label' => 'Saldo restante',     'description' => 'Saldo pendiente del cliente tras el pago.', 'sample' => '$0'],
                    'empresa'        => ['label' => 'Tu empresa',         'description' => 'Nombre comercial de tu workspace.',   'sample' => 'Mi ISP'],
                ],
            ],
        ];
    }

    /** Claves de evento válidas (para validación y loops). @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::events());
    }

    /** Solo eventos AUTOMÁTICOS (se disparan por fecha). @return array<int, string> */
    public static function autoKeys(): array
    {
        return array_keys(array_filter(self::events(), fn ($e) => $e['auto'] ?? false));
    }

    public static function has(?string $eventKey): bool
    {
        return $eventKey !== null && array_key_exists($eventKey, self::events());
    }

    public static function label(?string $eventKey): ?string
    {
        return self::events()[$eventKey]['label'] ?? null;
    }

    /**
     * Nombres de variable válidos para un evento (para validar el body de la plantilla).
     *
     * @return array<int, string>
     */
    public static function variableNames(?string $eventKey): array
    {
        if (! self::has($eventKey)) {
            return [];
        }
        return array_keys(self::events()[$eventKey]['variables']);
    }

    /**
     * Mapa { nombre_variable => valor de ejemplo } de un evento. Sirve para
     * autocompletar las muestras que Meta exige sin que el cliente las escriba.
     *
     * @return array<string, string>
     */
    public static function samples(?string $eventKey): array
    {
        if (! self::has($eventKey)) {
            return [];
        }
        return array_map(fn ($v) => $v['sample'], self::events()[$eventKey]['variables']);
    }

    /**
     * Catálogo en la forma que consume el frontend (Inertia): lista de eventos,
     * cada uno con sus variables como lista ordenada.
     *
     * @param  bool  $onlyAuto  Si true, solo los eventos automáticos (para Settings).
     * @return array<int, array<string, mixed>>
     */
    public static function forFrontend(bool $onlyAuto = false): array
    {
        $out = [];
        foreach (self::events() as $key => $event) {
            if ($onlyAuto && ! ($event['auto'] ?? false)) {
                continue;
            }
            $variables = [];
            foreach ($event['variables'] as $name => $meta) {
                $variables[] = [
                    'name'        => $name,
                    'label'       => $meta['label'],
                    'description' => $meta['description'],
                    'sample'      => $meta['sample'],
                ];
            }
            $out[] = [
                'key'         => $key,
                'label'       => $event['label'],
                'description' => $event['description'],
                'trigger'     => $event['trigger'],
                'auto'        => (bool) ($event['auto'] ?? false),
                'variables'   => $variables,
            ];
        }
        return $out;
    }

    /**
     * Valores REALES de las variables de un evento para un cliente concreto.
     * Devuelve { nombre_variable => valor ya formateado }.
     *
     * @param  array<string, mixed>  $row  Fila de IspwatchRepository (cycleCustomersForBilling
     *                                     para eventos auto, customerByPhone para "general").
     * @param  string|null  $agentName  Nombre del agente que envía (solo "general", envío manual).
     * @return array<string, string>
     */
    public static function resolveValues(string $eventKey, array $row, Tenant $tenant, ?string $agentName = null): array
    {
        $money = fn ($v): string => '$' . number_format((float) $v, 0, ',', '.');
        $date  = fn ($v): string => $v ? Carbon::parse($v)->format('d/m/Y') : '';
        // Mes en texto (es), del mes de emisión de la factura; respaldo al vencimiento.
        $month = fn ($v): string => $v ? Carbon::parse($v)->locale('es')->isoFormat('MMMM') : '';
        $first = fn ($v): string => ($v = trim((string) $v)) !== '' ? (explode(' ', $v)[0] ?? '') : '';
        // Saludo según la hora local del ISP (misma zona que usan los avisos).
        $greeting = function (): string {
            $tz   = config('services.whatsapp.billing_notify_timezone', 'America/Bogota');
            $hour = (int) Carbon::now($tz)->format('H');
            return $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
        };

        return match ($eventKey) {
            'invoice_created' => [
                'nombre_cliente'    => (string) $row['customer_name'],
                'monto'             => $money($row['total'] ?? $row['balance_due']),
                'mes_facturado'     => $month($row['issue_date'] ?? $row['due_date'] ?? null),
                'numero_factura'    => (string) ($row['invoice_number'] ?? ''),
                'fecha_vencimiento' => $date($row['due_date'] ?? null),
                'empresa'           => (string) $tenant->name,
            ],
            'payment_reminder' => [
                'nombre_cliente'    => (string) $row['customer_name'],
                'saldo'             => $money($row['balance_due'] ?? $row['total']),
                'mes_facturado'     => $month($row['issue_date'] ?? $row['due_date'] ?? null),
                'numero_factura'    => (string) ($row['invoice_number'] ?? ''),
                'fecha_vencimiento' => $date($row['due_date'] ?? null),
                'empresa'           => (string) $tenant->name,
            ],
            'service_suspension' => [
                'nombre_cliente'    => (string) $row['customer_name'],
                'saldo'             => $money($row['balance_due'] ?? $row['total']),
                'mes_facturado'     => $month($row['issue_date'] ?? $row['due_date'] ?? null),
                'numero_factura'    => (string) ($row['invoice_number'] ?? ''),
                'fecha_vencimiento' => $date($row['due_date'] ?? null),
                // Se envía el día de corte, así que "hoy" es la fecha de corte.
                'fecha_corte'       => Carbon::now()->format('d/m/Y'),
                'empresa'           => (string) $tenant->name,
            ],
            'service_activated' => [
                'nombre_cliente' => (string) $row['customer_name'],
                'primer_nombre'  => $first($row['customer_name'] ?? ''),
                'plan'           => (string) ($row['plan_name'] ?? ''),
                'usuario_pppoe'  => (string) ($row['pppoe_username'] ?? ''),
                'direccion'      => (string) ($row['address'] ?? ''),
                'empresa'        => (string) $tenant->name,
                'saludo'         => $greeting(),
                'fecha_actual'   => Carbon::now()->format('d/m/Y'),
            ],
            'payment_registered' => [
                'nombre_cliente' => (string) $row['customer_name'],
                'primer_nombre'  => $first($row['customer_name'] ?? ''),
                'monto'          => $money($row['amount'] ?? 0),
                'metodo'         => (string) ($row['method'] ?? ''),
                'referencia'     => (string) ($row['reference'] ?? ''),
                'fecha_pago'     => $date($row['payment_date'] ?? null),
                'saldo_restante' => $money($row['pending_balance'] ?? 0),
                'empresa'        => (string) $tenant->name,
            ],
            'general' => self::generalValues($row, $tenant, $agentName, $money),
            default   => [],
        };
    }

    /**
     * Valores del propósito "general": cliente (ispwatch customerByPhone) + empresa
     * + fecha/hora. Listo para el futuro envío manual de plantillas desde el chat.
     *
     * @param  array<string, mixed>  $row     Forma de IspwatchRepository::customerByPhone.
     * @param  callable  $money  Formateador de moneda.
     * @return array<string, string>
     */
    private static function generalValues(array $row, Tenant $tenant, ?string $agentName, callable $money): array
    {
        $now    = Carbon::now()->locale('es');
        $name   = trim((string) ($row['name'] ?? ''));
        $first  = $name !== '' ? (explode(' ', $name)[0] ?? '') : '';
        $hour   = (int) $now->format('H');
        $saludo = $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');

        return [
            'nombre_cliente'  => $name,
            'primer_nombre'   => $first,
            'correo'          => (string) ($row['email'] ?? ''),
            'telefono'        => (string) ($row['tel'] ?? ''),
            'cedula'          => (string) ($row['cedula'] ?? ''),
            'documento'       => (string) ($row['document_number'] ?? ''),
            'usuario_pppoe'   => (string) ($row['pppoe_username'] ?? ''),
            'estado_servicio' => (string) ($row['service_status'] ?? ''),
            'saldo_favor'     => $money($row['credit_balance'] ?? 0),
            'direccion'       => (string) ($row['address'] ?? ''),
            'ip_cliente'      => (string) ($row['ip_user'] ?? ''),
            'empresa'         => (string) $tenant->name,
            'agente'          => (string) ($agentName ?? ''),
            'saludo'          => $saludo,
            'fecha_actual'    => $now->format('d/m/Y'),
            'hora_actual'     => $now->format('H:i'),
            'dia_semana'      => $now->isoFormat('dddd'),
            'mes_actual'      => $now->isoFormat('MMMM'),
            'anio_actual'     => $now->format('Y'),
            'fecha_larga'     => $now->isoFormat('D [de] MMMM [de] YYYY'),
        ];
    }
}
