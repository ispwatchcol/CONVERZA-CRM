<?php

namespace App\Services\Flows;

/**
 * Resultado de buscar al contacto en ispwatch (solo lectura).
 *
 * `unavailable` separa "no está" de "no se pudo mirar": un tenant sin vincular,
 * un contacto que ocultó su número o una caída de la base de ispwatch. Para el
 * flujo las dos terminan en la salida "No encontrado", pero la traza dice cuál
 * fue, que es lo que se necesita para diagnosticar.
 */
final class CustomerLookup
{
    /**
     * @param array<string, mixed>|null        $customer Forma de IspwatchRepository::customerByPhone()
     * @param list<array<string, mixed>>       $invoices Forma de IspwatchRepository::pendingInvoicesForCustomer()
     */
    private function __construct(
        public readonly string $status,
        public readonly ?array $customer = null,
        public readonly array $invoices = [],
        public readonly ?string $reason = null,
    ) {}

    public static function found(array $customer, array $invoices): self
    {
        return new self('found', $customer, array_values($invoices));
    }

    public static function notFound(): self
    {
        return new self('not_found', reason: 'no_registrado');
    }

    /** @param 'sin_vinculo'|'sin_telefono'|'error' $reason */
    public static function unavailable(string $reason): self
    {
        return new self('not_found', reason: $reason);
    }

    public function isFound(): bool
    {
        return $this->status === 'found';
    }
}
