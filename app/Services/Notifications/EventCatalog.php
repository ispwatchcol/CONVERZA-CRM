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
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     trigger: string,
     *     variables: array<string, array{label: string, description: string, sample: string}>
     * }>
     */
    public static function events(): array
    {
        return [
            'invoice_created' => [
                'label'       => 'Factura generada',
                'description' => 'Avisa al cliente que su factura del ciclo ya fue generada.',
                'trigger'     => 'El día que el router crea la factura (campo create_invoice del billing).',
                'variables'   => [
                    'nombre_cliente'    => ['label' => 'Nombre del cliente',    'description' => 'Nombre del cliente en ispwatch.',        'sample' => 'Juan Pérez'],
                    'monto'             => ['label' => 'Monto de la factura',   'description' => 'Total de la factura del ciclo.',          'sample' => '$45.000'],
                    'numero_factura'    => ['label' => 'Número de factura',     'description' => 'Consecutivo de la factura.',              'sample' => 'FAC-1234'],
                    'fecha_vencimiento' => ['label' => 'Fecha de vencimiento',  'description' => 'Fecha límite de pago (formato d/m/Y).',   'sample' => '15/06/2026'],
                    'empresa'           => ['label' => 'Tu empresa',            'description' => 'Nombre comercial de tu workspace.',       'sample' => 'Mi ISP'],
                ],
            ],

            'payment_reminder' => [
                'label'       => 'Recordatorio de pago',
                'description' => 'Recuerda al cliente que tiene un saldo pendiente por pagar.',
                'trigger'     => 'El día de recordatorio del router (campo payment_reminder del billing), si está habilitado.',
                'variables'   => [
                    'nombre_cliente'    => ['label' => 'Nombre del cliente',    'description' => 'Nombre del cliente en ispwatch.',        'sample' => 'Juan Pérez'],
                    'saldo'             => ['label' => 'Saldo pendiente',       'description' => 'Saldo aún por pagar de la factura.',      'sample' => '$45.000'],
                    'numero_factura'    => ['label' => 'Número de factura',     'description' => 'Consecutivo de la factura.',              'sample' => 'FAC-1234'],
                    'fecha_vencimiento' => ['label' => 'Fecha de vencimiento',  'description' => 'Fecha límite de pago (formato d/m/Y).',   'sample' => '15/06/2026'],
                    'empresa'           => ['label' => 'Tu empresa',            'description' => 'Nombre comercial de tu workspace.',       'sample' => 'Mi ISP'],
                ],
            ],
        ];
    }

    /** Claves de evento válidas (para validación y loops). @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::events());
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
     * @return array<int, array<string, mixed>>
     */
    public static function forFrontend(): array
    {
        $out = [];
        foreach (self::events() as $key => $event) {
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
                'variables'   => $variables,
            ];
        }
        return $out;
    }

    /**
     * Valores REALES de las variables de un evento para un cliente concreto.
     * Devuelve { nombre_variable => valor ya formateado }.
     *
     * @param  array<string, mixed>  $row  Fila de IspwatchRepository::cycleCustomersForBilling
     * @return array<string, string>
     */
    public static function resolveValues(string $eventKey, array $row, Tenant $tenant): array
    {
        $money = fn ($v): string => '$' . number_format((float) $v, 0, ',', '.');
        $date  = fn ($v): string => $v ? Carbon::parse($v)->format('d/m/Y') : '';

        return match ($eventKey) {
            'invoice_created' => [
                'nombre_cliente'    => (string) $row['customer_name'],
                'monto'             => $money($row['total'] ?? $row['balance_due']),
                'numero_factura'    => (string) ($row['invoice_number'] ?? ''),
                'fecha_vencimiento' => $date($row['due_date'] ?? null),
                'empresa'           => (string) $tenant->name,
            ],
            'payment_reminder' => [
                'nombre_cliente'    => (string) $row['customer_name'],
                'saldo'             => $money($row['balance_due'] ?? $row['total']),
                'numero_factura'    => (string) ($row['invoice_number'] ?? ''),
                'fecha_vencimiento' => $date($row['due_date'] ?? null),
                'empresa'           => (string) $tenant->name,
            ],
            default => [],
        };
    }
}
