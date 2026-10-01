<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use Carbon\Carbon;

/**
 * Busca al contacto en ispwatch por su teléfono (SOLO LECTURA, vía
 * IspwatchRepository) y deja sus datos en variables `cliente.*`.
 *
 * Nunca escribe en ispwatch: la conexión es de solo lectura y además el FlowIO
 * no ofrece ninguna operación de escritura hacia allá.
 */
final class CustomerLookupNode extends NodeType
{
    /** Máximo de facturas enumeradas en cliente.detalle_pendientes. */
    private const DETAIL_MAX_INVOICES = 6;

    public const VARIABLES = [
        'cliente.encontrado'          => '"sí" o "no"',
        'cliente.nombre'              => 'Nombre completo en ispwatch',
        'cliente.primer_nombre'       => 'Primer nombre en ispwatch',
        'cliente.cedula'              => 'Cédula o documento',
        'cliente.estado_servicio'     => 'Estado del servicio (Activo, Suspendido…)',
        'cliente.usuario_pppoe'       => 'Usuario PPPoE',
        'cliente.direccion'           => 'Dirección registrada',
        'cliente.saldo_favor'         => 'Saldo a favor',
        'cliente.facturas_pendientes' => 'Cuántas facturas tiene sin pagar',
        'cliente.total_pendiente'     => 'Suma de lo que debe',
        'cliente.proximo_vencimiento' => 'Fecha de la factura pendiente más antigua',
        'cliente.detalle_pendientes'  => 'Lista de facturas pendientes con valor y vencimiento',
    ];

    /** Estados de servicio de ispwatch, en palabras para el cliente. */
    private const SERVICE_STATUS = [
        'active'    => 'Activo',
        'gratis'    => 'Activo',
        'suspended' => 'Suspendido',
        'suspendido'=> 'Suspendido',
        'inactive'  => 'Inactivo',
        'cut'       => 'Cortado',
        'cortado'   => 'Cortado',
        'retired'   => 'Retirado',
    ];

    public function type(): string
    {
        return 'ispwatch';
    }

    public function label(): string
    {
        return 'Datos del cliente';
    }

    public function outputs(array $data): array
    {
        return ['found', 'not_found'];
    }

    public function outputLabel(string $handle, array $data): string
    {
        return $handle === 'found' ? 'Encontrado' : 'No encontrado';
    }

    public function definesVariables(array $data): array
    {
        return array_keys(self::VARIABLES);
    }

    public function enter(array $node, Execution $ex): Step
    {
        // Se limpian antes de buscar: si el flujo pasa dos veces por aquí, no
        // pueden quedar datos de la búsqueda anterior.
        foreach (array_keys(self::VARIABLES) as $name) {
            $ex->set($name, '');
        }

        $lookup = $ex->io->lookupCustomer();

        if (! $lookup->isFound()) {
            $ex->set('cliente.encontrado', 'no');
            $ex->note(detail: ['motivo' => $lookup->reason]);

            return Step::next('not_found');
        }

        $customer = $lookup->customer;
        $invoices = $lookup->invoices;
        $name     = trim((string) ($customer['name'] ?? ''));
        $status   = strtolower(trim((string) ($customer['service_status'] ?? '')));

        $ex->set('cliente.encontrado', 'sí');
        $ex->set('cliente.nombre', $name);
        $ex->set('cliente.primer_nombre', $name === '' ? '' : (preg_split('/\s+/u', $name)[0] ?? ''));
        $ex->set('cliente.cedula', (string) (($customer['cedula'] ?? '') ?: ($customer['document_number'] ?? '')));
        $ex->set('cliente.estado_servicio', self::SERVICE_STATUS[$status] ?? ucfirst($status));
        $ex->set('cliente.usuario_pppoe', (string) ($customer['pppoe_username'] ?? ''));
        $ex->set('cliente.direccion', (string) ($customer['address'] ?? ''));
        $ex->set('cliente.saldo_favor', $this->money($customer['credit_balance'] ?? 0));

        // balance_due, nunca total: ispwatch descuenta el saldo a favor al crear
        // la factura y cobrarle el bruto es cobrarle algo ya saldado.
        $total = array_sum(array_map(fn (array $i) => (float) ($i['balance_due'] ?? 0), $invoices));
        $ex->set('cliente.facturas_pendientes', (string) count($invoices));
        $ex->set('cliente.total_pendiente', $this->money($total));
        $ex->set('cliente.proximo_vencimiento', $this->date($invoices[0]['due_date'] ?? null));
        $ex->set('cliente.detalle_pendientes', $this->detail($invoices));

        $ex->note(detail: [
            'cliente'  => $name,
            'facturas' => count($invoices),
            'ambiguo'  => (bool) ($customer['ambiguous'] ?? false),
        ]);

        return Step::next('found');
    }

    /** Una línea por factura: en un mensaje de WhatsApp los saltos sí se ven. */
    private function detail(array $invoices): string
    {
        $lines = array_map(
            fn (array $i) => trim('• ' . ($i['number'] ?? '') . ' · ' . $this->money($i['balance_due'] ?? 0)
                . (($i['due_date'] ?? null) ? ' · vence ' . $this->date($i['due_date']) : '')),
            array_slice($invoices, 0, self::DETAIL_MAX_INVOICES),
        );

        $extra = count($invoices) - self::DETAIL_MAX_INVOICES;
        if ($extra > 0) {
            $lines[] = "• y {$extra} más";
        }

        return implode("\n", $lines);
    }

    private function money(mixed $value): string
    {
        return '$' . number_format((float) $value, 0, ',', '.');
    }

    private function date(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
